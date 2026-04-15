<?php

namespace TranslandShipping\Controllers;

use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Order\Shipping\Information\Contracts\ShippingInformationRepositoryContract;
use Plenty\Modules\Order\Shipping\Package\Contracts\OrderShippingPackageRepositoryContract;
use Plenty\Modules\Order\Shipping\PackageType\Contracts\ShippingPackageTypeRepositoryContract;
use Plenty\Modules\Plugin\Storage\Contracts\StorageRepositoryContract;
use Plenty\Modules\Tag\Contracts\TagRepositoryContract;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract as OrderRepo;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Log\Loggable;
use TranslandShipping\Services\LabelService;
use TranslandShipping\Services\ShippingListService;
use TranslandShipping\Services\SettingsService;
use TranslandShipping\Services\StorageService;

/**
 * ShippingController
 *
 * Implements the PlentyONE Shipping Provider Interface.
 * registerShipments() is called by the PlentyONE process action "RegisterShipment"
 * when the shipping provider "TranslandShipping" is selected.
 *
 * Flow:
 *   1. PlentyONE Prozess -> ShippingPackages (Versandcenter)
 *   2. PlentyONE Prozess -> RegisterShipment (ruft registerShipments() auf)
 *   3. registerShipments() -> Transland API -> ZPL Label
 *   4. ZPL wird in S3 gespeichert -> URL wird an PlentyONE zurückgegeben
 *   5. PlentyONE/plentyBase druckt Label über native Druckaktion
 *   6. SSCC wird als Paketnummer gespeichert
 */
class ShippingController extends Controller
{
    use Loggable;

    /** Versandprofil-IDs die als Speditionsversand gelten */
    private const SPEDITION_PROFILE_IDS = [8, 95, 122, 124];

    /** Ziel-Versandprofil nach Label-Druck */
    private const TARGET_SHIPPING_PROFILE_ID = 111;

    /** Ziel-Status nach Label-Druck (7.0 = Warenausgang) */
    private const TARGET_ORDER_STATUS = 7.0;

    /** Tag-Name nach erfolgreichem Label-Druck */
    private const TAG_AUT_ANMELDUNG = 'Aut. Anmeldung';

    /** Tag-Name fuer Gefahrstoffe */
    private const TAG_GEFAHRSTOFF = 'Gefahrenstoff';

    /** Ergebnis-Array fuer PlentyONE */
    private array $createOrderResult = [];

    private Request                              $request;
    private OrderRepositoryContract             $orderRepository;
    private OrderShippingPackageRepositoryContract $orderShippingPackage;
    private ShippingInformationRepositoryContract  $shippingInformation;
    private StorageRepositoryContract             $storageRepository;
    private ShippingPackageTypeRepositoryContract  $packageTypeRepository;
    private LabelService                          $labelService;
    private ShippingListService                   $shippingListService;
    private SettingsService                       $settingsService;
    private StorageService                        $storageService;

    public function __construct(
        Request                                $request,
        OrderRepositoryContract                $orderRepository,
        OrderShippingPackageRepositoryContract $orderShippingPackage,
        ShippingInformationRepositoryContract  $shippingInformation,
        StorageRepositoryContract              $storageRepository,
        ShippingPackageTypeRepositoryContract  $packageTypeRepository,
        LabelService                           $labelService,
        ShippingListService                    $shippingListService,
        SettingsService                        $settingsService,
        StorageService                         $storageService
    ) {
        $this->request              = $request;
        $this->orderRepository      = $orderRepository;
        $this->orderShippingPackage = $orderShippingPackage;
        $this->shippingInformation  = $shippingInformation;
        $this->storageRepository    = $storageRepository;
        $this->packageTypeRepository = $packageTypeRepository;
        $this->labelService         = $labelService;
        $this->shippingListService  = $shippingListService;
        $this->settingsService      = $settingsService;
        $this->storageService       = $storageService;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // registerShipments – wird von PlentyONE Prozess aufgerufen
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Registers shipments with Transland API.
     * Called by PlentyONE process action "RegisterShipment".
     *
     * @param Request $request
     * @param array|int $orderIds
     * @return array
     */
    public function registerShipments(Request $request, $orderIds): array
    {
        $orderIds     = $this->getOrderIds($request, $orderIds);
        $orderIds     = $this->getOpenOrderIds($orderIds);
        $shipmentDate = date('Y-m-d');

        foreach ($orderIds as $orderId) {
            $orderId = (int)$orderId;

            try {
                // 1. Auftrag laden
                $order = $this->orderRepository->findOrderById($orderId, [
                    'addresses',
                    'amounts',
                    'tags',
                    'properties',
                ]);

                if (!$order || !$order->id) {
                    $this->createOrderResult[$orderId] = $this->buildResultArray(
                        false, 'Order not found: ' . $orderId, false, []
                    );
                    continue;
                }

                // 2. Versandprofil-Check
                if (!$this->hasSpeditionProfile($order)) {
                    $this->getLogger(__CLASS__)->error('TranslandShipping::register.profileSkipped', [
                        'orderId'           => $orderId,
                        'shippingProfileId' => $order->shippingProfileId ?? 0,
                    ]);
                    $this->createOrderResult[$orderId] = $this->buildResultArray(
                        false,
                        'Kein Speditions-Versandprofil (erlaubt: 8, 95, 122, 124). Aktuell: ' . ($order->shippingProfileId ?? 0),
                        false,
                        []
                    );
                    continue;
                }

                // Note: Haupt- und Lieferauftraege werden beide akzeptiert.
                // Der Packer scannt das, was auf seinem Zettel steht, in
                // beliebiger Reihenfolge. Die Familie-Logik (Sendungen erst
                // \u00fcbermitteln wenn alle Spedition-Geschwister gelabelt sind)
                // passiert ausschliesslich im Daily-Cron via
                // ShippingListService::submitDailyShipments().

                // 3. Gefahrstoff-Check
                $hasHazmat = $this->hasTag($order, self::TAG_GEFAHRSTOFF);
                if ($hasHazmat) {
                    $this->getLogger(__CLASS__)->error('TranslandShipping::register.hazmat_detected', [
                        'orderId' => $orderId,
                        'note'    => 'Gefahrenstoff-Tag erkannt. dangerous_goods wird aus Plugin-Config "Gefahrgut" gebaut.',
                    ]);
                }

                // 3a. NextDay-Tag-Erkennung → Zufall Premium-Service Options
                //     Die 4 Tags (NextDay, NextDay8, NextDay10, NextDay12)
                //     werden in buildShipmentOptions() auf die Option-Codes
                //     250/253/255/257 gemappt. Nur der spezifischste Tag zieht.
                $orderTagNames = $this->collectOrderTagNames($order);
                $shipmentOptions = $this->payloadBuilder->buildShipmentOptions($orderTagNames);
                if (!empty($shipmentOptions)) {
                    $this->getLogger(__CLASS__)->error('TranslandShipping::register.nextday_detected', [
                        'orderId' => $orderId,
                        'options' => $shipmentOptions,
                    ]);
                }

                // 4. Pakete aus Versandcenter laden
                $plentyPackages = $this->orderShippingPackage->listOrderShippingPackages($orderId);

                if (empty($plentyPackages)) {
                    $this->createOrderResult[$orderId] = $this->buildResultArray(
                        false, 'Keine Pakete im Versandcenter', false, []
                    );
                    continue;
                }

                // 5. Pakete aufbereiten mit Paketvorlage aus Versandcenter
                $packages = $this->buildPackagesFromVersandcenter($plentyPackages, $hasHazmat);

                // 5a. Gefahrgut-Block aus Plugin-Config an JEDE Position anhängen
                //     wenn der Auftrag als Gefahrenstoff markiert ist.
                //     buildDangerousGoodsFromConfig() gibt ein leeres Array
                //     zurück wenn un_number nicht konfiguriert ist – in dem
                //     Fall wird nichts angehängt (Schutz vor HTTP 500).
                if ($hasHazmat) {
                    $hazmatEntry = $this->payloadBuilder->buildDangerousGoodsFromConfig();
                    if (!empty($hazmatEntry)) {
                        foreach ($packages as &$pkg) {
                            $pkg['dangerous_goods'] = [$hazmatEntry];
                        }
                        unset($pkg);
                        $this->getLogger(__CLASS__)->error('TranslandShipping::register.hazmat_applied', [
                            'orderId'   => $orderId,
                            'un_number' => $hazmatEntry['un_number'] ?? '',
                            'name'      => $hazmatEntry['name'] ?? '',
                        ]);
                    } else {
                        $this->getLogger(__CLASS__)->error('TranslandShipping::register.hazmat_config_empty', [
                            'orderId' => $orderId,
                            'note'    => 'Gefahrenstoff-Tag gesetzt aber Plugin-Config "Gefahrgut" hat keine UN-Nummer. Label wird OHNE dangerous_goods-Block erstellt.',
                        ]);
                    }
                }

                $this->getLogger(__CLASS__)->error('TranslandShipping::register.packagesLoaded', [
                    'orderId'      => $orderId,
                    'packageCount' => count($packages),
                    'packageTypes' => array_column($packages, '_package_type_raw'),
                    'weights'      => array_column($packages, 'weight_gr'),
                ]);

                // 6. Auftrag als Array aufbereiten
                $orderArray    = $this->orderToArray($order);
                $parentOrderId = (int)($order->parentOrderId ?? 0);

                // 7. ZPL Label erstellen via Transland API
                //    $shipmentOptions enthält NextDay-Codes wenn entsprechender Tag gesetzt.
                $result = $this->labelService->createLabelForOrder($orderArray, $packages, 'ZPL', $shipmentOptions);

                if (empty($result['label_data'])) {
                    $this->createOrderResult[$orderId] = $this->buildResultArray(
                        false, 'Transland API hat kein ZPL zurueckgegeben', false, []
                    );
                    continue;
                }

                // 8. ZPL in PlentyONE S3 Storage speichern
                //    PlentyONE liest die URL und schickt sie via plentyBase an den Zebra-Drucker
                $sscc        = $result['sscc_list'][0] ?? ('transland-' . $orderId);
                $storageKey  = $sscc . '.zpl';
                $storageObject = $this->storageRepository->uploadObject(
                    'TranslandShipping',
                    $storageKey,
                    $result['label_data']
                );

                // Resolve a real label URL that plentyBase can fetch + print.
                // Tries multiple Storage API methods because the exact signature
                // varies between Plenty Stable releases.
                $labelUrl = $this->resolveLabelUrl('TranslandShipping', $storageKey, $storageObject);

                // 9. SSCC als Paketnummer in Versandcenter-Pakete schreiben
                $shipmentItems = [];
                foreach ($plentyPackages as $idx => $plentyPkg) {
                    $pkgSscc = $result['packages'][$idx]['sscc'] ?? $sscc;

                    $this->orderShippingPackage->updateOrderShippingPackage(
                        $plentyPkg->id,
                        [
                            'packageNumber' => $pkgSscc,
                            'label'         => $labelUrl,
                        ]
                    );

                    $shipmentItems[] = [
                        'labelUrl'       => $labelUrl,
                        'shipmentNumber' => $pkgSscc,
                    ];
                }

                // 10. ShippingInformation speichern (PlentyONE Standard)
                $this->saveShippingInformation($orderId, $shipmentDate, $shipmentItems);

                // 11. Sendungsdaten + ZPL fuer Bordero in Plugin-DB speichern
                $shipmentData                    = $result['shipment_data'];
                $shipmentData['parent_order_id'] = $parentOrderId;
                $shipmentData['has_hazmat']      = $hasHazmat ? 1 : 0;
                $shipmentData['zpl_data']        = $result['label_data'];

                $this->shippingListService->storeShipmentAfterLabel($shipmentData);

                // 12. Post-Label-Aktionen: Profil 111, Status 7.0, Tag setzen
                $this->runPostLabelActions($order);

                $this->getLogger(__CLASS__)->error('TranslandShipping::register.success', [
                    'orderId'       => $orderId,
                    'parentOrderId' => $parentOrderId,
                    'ssccList'      => $result['sscc_list'],
                    'storageKey'    => $storageObject->key ?? $storageKey,
                    'hasHazmat'     => $hasHazmat ? 'JA' : 'NEIN',
                ]);

                $this->createOrderResult[$orderId] = $this->buildResultArray(
                    true,
                    'Sendung erfolgreich bei Transland angemeldet. SSCC: ' . implode(', ', $result['sscc_list']),
                    false,
                    $shipmentItems
                );

            } catch (\Exception $e) {
                $this->getLogger(__CLASS__)->error('TranslandShipping::register.error', [
                    'orderId' => $orderId,
                    'message' => $e->getMessage(),
                    'trace'   => substr($e->getTraceAsString(), 0, 600),
                ]);

                $this->createOrderResult[$orderId] = $this->buildResultArray(
                    false,
                    'Fehler: ' . $e->getMessage(),
                    false,
                    []
                );
            }
        }

        return $this->createOrderResult;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // deleteShipments – Stornierung (PlentyONE Interface Pflicht)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cancels registered shipments.
     * Called by PlentyONE when user cancels a shipment.
     *
     * @param Request $request
     * @param array|int $orderIds
     * @return array
     */
    public function deleteShipments(Request $request, $orderIds): array
    {
        $orderIds = $this->getOrderIds($request, $orderIds);

        foreach ($orderIds as $orderId) {
            $orderId = (int)$orderId;

            try {
                $shippingInfo = $this->shippingInformation->getShippingInformationByOrderId($orderId);

                if (isset($shippingInfo->additionalData) && is_array($shippingInfo->additionalData)) {
                    foreach ($shippingInfo->additionalData as $additionalData) {
                        $shipmentNumber = $additionalData['shipmentNumber'] ?? '';

                        // Transland hat keine Stornierung in v1.3 – loggen und als OK melden
                        $this->getLogger(__CLASS__)->error('TranslandShipping::delete.requested', [
                            'orderId'        => $orderId,
                            'shipmentNumber' => $shipmentNumber,
                            'note'           => 'Stornierung bei Transland muss manuell erfolgen. API v1.3 unterstuetzt keine automatische Stornierung.',
                        ]);

                        $this->createOrderResult[$orderId] = $this->buildResultArray(
                            true,
                            'Hinweis: Bitte Sendung ' . $shipmentNumber . ' manuell bei Transland stornieren.',
                            false,
                            []
                        );
                    }
                }

                // ShippingInformation in PlentyONE zuruecksetzen
                $this->shippingInformation->resetShippingInformation($orderId);

            } catch (\Exception $e) {
                $this->getLogger(__CLASS__)->error('TranslandShipping::delete.error', [
                    'orderId' => $orderId,
                    'message' => $e->getMessage(),
                ]);

                $this->createOrderResult[$orderId] = $this->buildResultArray(
                    false, $e->getMessage(), false, []
                );
            }
        }

        return $this->createOrderResult;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Hilfsmethoden
    // ─────────────────────────────────────────────────────────────────────────

    private function hasSpeditionProfile($order): bool
    {
        // Direkter Check. Die Familie-Logik (Sendungen erst uebermitteln wenn
        // alle Spedition-Geschwister gelabelt sind) lebt im Daily-Cron, nicht
        // hier. Am Packtisch gibt es keine Familie-Sicht.
        return in_array((int)($order->shippingProfileId ?? 0), self::SPEDITION_PROFILE_IDS, true);
    }

    private function hasTag($order, string $tagName): bool
    {
        if (empty($order->tags)) {
            return false;
        }
        foreach ($order->tags as $tag) {
            $name = $tag->tagName ?? ($tag->names[0]['name'] ?? ($tag->name ?? ''));
            if (trim($name) === $tagName) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return all tag names attached to an order as a flat string array.
     * Uses the same lookup pattern as hasTag() to handle the different
     * shapes Plenty's Tag model can take (tagName vs names[].name vs name).
     */
    private function collectOrderTagNames($order): array
    {
        $names = [];
        if (empty($order->tags)) {
            return $names;
        }
        foreach ($order->tags as $tag) {
            $name = $tag->tagName ?? ($tag->names[0]['name'] ?? ($tag->name ?? ''));
            $name = trim((string) $name);
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }

    private function buildPackagesFromVersandcenter(array $plentyPackages, bool $hasHazmat): array
    {
        return array_map(function ($pkg) use ($hasHazmat) {
            $packageTypeName = '';
            if (!empty($pkg->shippingPackageType) && !empty($pkg->shippingPackageType->name)) {
                $packageTypeName = $pkg->shippingPackageType->name;
            } elseif (!empty($pkg->packageType) && !empty($pkg->packageType->name)) {
                $packageTypeName = $pkg->packageType->name;
            } elseif (!empty($pkg->packageTypeName)) {
                $packageTypeName = $pkg->packageTypeName;
            }

            $package = [
                'content'           => 'Waren',
                'packaging_type'    => $packageTypeName,
                'length_cm'         => (int)($pkg->length ?? 0),
                'width_cm'          => (int)($pkg->width  ?? 0),
                'height_cm'         => (int)($pkg->height ?? 0),
                'weight_gr'         => (int)($pkg->weight ?? 0),
                '_package_type_raw' => $packageTypeName,
            ];

            // Gefahrstoff: Platzhalter bis Zufall API v2 Felder definiert
            // TODO: Felder ergaenzen wenn Venturama UN-Nummern in PlentyONE pflegt
            if ($hasHazmat) {
                $package['dangerous_goods'] = [];
            }

            return $package;
        }, $plentyPackages);
    }

    private function runPostLabelActions($order): void
    {
        $orderId = $order->id;

        // Versandprofil auf 111 aendern
        try {
            $this->orderRepository->updateOrder(
                ['shippingProfileId' => self::TARGET_SHIPPING_PROFILE_ID],
                $orderId
            );
        } catch (\Exception $e) {
            $this->getLogger(__CLASS__)->error('TranslandShipping::postLabel.profileError', [
                'orderId' => $orderId, 'error' => $e->getMessage(),
            ]);
        }

        // Status auf 7.0 setzen
        try {
            $this->orderRepository->updateOrder(
                ['statusId' => self::TARGET_ORDER_STATUS],
                $orderId
            );
        } catch (\Exception $e) {
            $this->getLogger(__CLASS__)->error('TranslandShipping::postLabel.statusError', [
                'orderId' => $orderId, 'error' => $e->getMessage(),
            ]);
        }

        // Tag "Aut. Anmeldung" setzen
        try {
            /** @var TagRepositoryContract $tagRepo */
            $tagRepo = pluginApp(TagRepositoryContract::class);
            $tagId   = $this->getOrCreateTagId($tagRepo, self::TAG_AUT_ANMELDUNG);
            if ($tagId > 0) {
                $tagRepo->createTagRelationship([
                    'tagId'     => $tagId,
                    'tagType'   => 'order',
                    'tagTypeId' => $orderId,
                ]);
            }
        } catch (\Exception $e) {
            $this->getLogger(__CLASS__)->error('TranslandShipping::postLabel.tagError', [
                'orderId' => $orderId, 'error' => $e->getMessage(),
            ]);
        }
    }

    private function getOrCreateTagId(TagRepositoryContract $tagRepo, string $tagName): int
    {
        try {
            $tags = $tagRepo->listTags(['name' => $tagName]);
            if (!empty($tags) && isset($tags[0])) {
                return (int)($tags[0]->id ?? 0);
            }
        } catch (\Exception $e) { }

        try {
            $tag = $tagRepo->createTag(['tagName' => $tagName, 'tagType' => 'order']);
            return (int)($tag->id ?? 0);
        } catch (\Exception $e) {
            $this->getLogger(__CLASS__)->error('TranslandShipping::postLabel.tagCreateError', [
                'tagName' => $tagName, 'error' => $e->getMessage(),
            ]);
        }
        return 0;
    }

    private function saveShippingInformation(int $orderId, string $shipmentDate, array $shipmentItems): void
    {
        $transactionIds = array_column($shipmentItems, 'shipmentNumber');

        $shipmentAt     = date(\DateTime::W3C, strtotime($shipmentDate));
        $registrationAt = date(\DateTime::W3C);

        $this->shippingInformation->saveShippingInformation([
            'orderId'                 => $orderId,
            'transactionId'           => implode(',', $transactionIds),
            'shippingServiceProvider' => 'TranslandShipping',
            'shippingStatus'          => 'registered',
            'shippingCosts'           => 0.00,
            'additionalData'          => $shipmentItems,
            'registrationAt'          => $registrationAt,
            'shipmentAt'              => $shipmentAt,
        ]);
    }

    private function orderToArray($order): array
    {
        $address = $order->deliveryAddress ?? $order->billingAddress ?? null;
        $phone   = '';
        $email   = '';

        if ($address && !empty($address->options)) {
            foreach ($address->options as $option) {
                if (($option->typeId ?? 0) == 4) { $phone = $option->value ?? ''; }
                if (($option->typeId ?? 0) == 5) { $email = $option->value ?? ''; }
            }
        }

        $addressArray = [];
        if ($address) {
            $addressArray = [
                'company'    => $address->companyName ?? '',
                'firstName'  => $address->firstName   ?? '',
                'lastName'   => $address->lastName    ?? '',
                'address1'   => $address->street      ?? '',
                'address2'   => $address->houseNumber ?? '',
                'postalCode' => $address->postalCode  ?? '',
                'town'       => $address->town        ?? '',
                'countryId'  => $address->countryId   ?? 1,
                'phone'      => $phone,
                'email'      => $email,
            ];
        }

        $amounts = [];
        foreach (($order->amounts ?? []) as $amount) {
            $amounts[] = [
                'isNet'        => $amount->isNet        ?? false,
                'invoiceTotal' => $amount->invoiceTotal ?? 0,
                'currency'     => $amount->currency     ?? 'EUR',
            ];
        }

        return [
            'id'              => $order->id,
            'externalOrderId' => $order->externalOrderId ?? '',
            'deliveryAddress' => $addressArray,
            'amounts'         => $amounts,
            'notes'           => '',
        ];
    }

    private function getOpenOrderIds(array $orderIds): array
    {
        $open = [];
        foreach ($orderIds as $orderId) {
            $info = $this->shippingInformation->getShippingInformationByOrderId((int)$orderId);
            if ($info->shippingStatus === null || $info->shippingStatus === 'open') {
                $open[] = $orderId;
            }
        }
        return $open;
    }

    private function getOrderIds(Request $request, $orderIds): array
    {
        if (is_numeric($orderIds)) {
            return [(int)$orderIds];
        }
        if (is_array($orderIds)) {
            return $orderIds;
        }
        return $request->get('orderIds', []);
    }

    private function buildResultArray(bool $success, string $message, bool $newPackage, array $shipmentItems): array
    {
        return [
            'success'          => $success,
            'message'          => $message,
            'newPackagenumber' => $newPackage,
            'packages'         => $shipmentItems,
        ];
    }

    /**
     * Resolves a real, fetchable URL for a stored label.
     *
     * plentyBase's RegisterShipment action takes the 'labelUrl' returned by a
     * shipping plugin and fetches it via HTTP to send to the connected label
     * printer. We MUST return an actual URL, not the storage key.
     *
     * The Plenty StorageRepositoryContract has changed signatures across
     * Stable versions — different method names, different parameter orders,
     * sometimes public URLs, sometimes signed temporary URLs. This helper
     * tries them in order and logs each attempt so we can see in the plugin
     * log which path succeeded on this specific Plenty version.
     *
     * Uses the verified Plenty Stable 7 signature:
     *   public getObjectUrl(
     *       string $pluginName,
     *       string $key,
     *       bool $publicVisible = false,
     *       int $minutesToExpire = 5
     *   ): string
     *
     * publicVisible MUST match what was passed to uploadObject(). We upload
     * without making the object public, so false here. Default expiry is
     * 5 minutes which is too short for packing-desk workflows, so we pass
     * 60 minutes explicitly.
     *
     * Note: Plenty's plugin sandbox forbids method_exists(), Reflection,
     * and dynamic property access — so this helper is kept intentionally
     * simple. If the URL resolve ever starts failing on a future Plenty
     * version, the fix is to look at the Plenty interface docs and adjust
     * the call here directly.
     */
    private function resolveLabelUrl(string $plugin, string $storageKey, $storageObject): string
    {
        try {
            $url = $this->storageRepository->getObjectUrl($plugin, $storageKey, false, 60);
            if (is_string($url) && $url !== '' && strpos($url, 'http') === 0) {
                $this->getLogger(__CLASS__)->error('TranslandShipping::label.urlResolved', [
                    'strategy' => 'getObjectUrl(plugin,key,false,60)',
                    'url'      => $url,
                ]);
                return $url;
            }

            // Method returned something unexpected (empty / not a URL)
            $this->getLogger(__CLASS__)->error('TranslandShipping::label.urlUnexpected', [
                'note'     => 'getObjectUrl did not return a valid http(s) URL',
                'returned' => is_string($url) ? $url : '<non-string>',
            ]);
        } catch (\Throwable $e) {
            $this->getLogger(__CLASS__)->error('TranslandShipping::label.urlException', [
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback: the storage key — not an URL, plentyBase will likely fail
        // to print, but at least we keep the process alive and have a log trail.
        $fallback = $storageObject->key ?? $storageKey;
        $this->getLogger(__CLASS__)->error('TranslandShipping::label.urlFallback', [
            'note'       => 'getObjectUrl failed. Printing will not work.',
            'storageKey' => $fallback,
        ]);
        return $fallback;
    }
}