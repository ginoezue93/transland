<?php

namespace TranslandShipping\Services;

use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;

class ShippingListService
{
    use Loggable;

    /**
     * Versandprofil-IDs die als Speditionsversand gelten.
     * Muss synchron mit ShippingController::SPEDITION_PROFILE_IDS gehalten werden.
     */
    private const SPEDITION_PROFILE_IDS = [8, 95, 122, 124];

    /**
     * Auftragsstatus-Werte, die als "storniert" gelten und bei der
     * Familie-Vollstaendigkeitspruefung uebersprungen werden.
     * Status 8.0 = Auftrag storniert in plentymarkets.
     */
    private const CANCELLED_STATUS_IDS = [8.0];

    private TranslandApiService $apiService;
    private PayloadBuilderService $payloadBuilder;
    private StorageService $storageService;
    private OrderRepositoryContract $orderRepository;

    public function __construct(
        TranslandApiService $apiService,
        PayloadBuilderService $payloadBuilder,
        StorageService $storageService,
        OrderRepositoryContract $orderRepository
    ) {
        $this->apiService = $apiService;
        $this->payloadBuilder = $payloadBuilder;
        $this->storageService = $storageService;
        $this->orderRepository = $orderRepository;
    }

    /**
     * Alle ausstehenden Sendungen als Bordero einreichen.
     *
     * WICHTIG: Wenn kein $pickupDate übergeben wird, werden ALLE pending Shipments
     * unabhängig vom Datum eingereicht (sinnvoll wenn Labels an Vortagen gedruckt wurden).
     */
    public function submitDailyShipments(string $pickupDate = '', bool $returnList = true): array
    {
        $pendingShipments = $this->storageService->getPendingShipments($pickupDate);

        if (empty($pendingShipments)) {
            return [
                'result' => 'no_pending',
                'list_id' => '',
                'shipment_count' => 0,
                'listPDF' => null,
                'submitted_order_ids' => [],
            ];
        }

        // ── Familie-Vollstaendigkeitspruefung ─────────────────────────────
        // Pending Sendungen werden nach Familie gruppiert. Eine Familie darf
        // erst in den Bordero, wenn ALLE ihre Spedition-Mitglieder ein Label
        // haben (also als pending Shipment in der Plugin-DB liegen).
        // Unvollstaendige Familien bleiben pending und warten auf den
        // naechsten Cron-Lauf.
        $completeShipments = $this->filterByCompleteFamilies($pendingShipments);

        if (empty($completeShipments)) {
            $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.allFamiliesIncomplete', [
                'pending_total' => count($pendingShipments),
                'note'          => 'Alle pending Sendungen gehoeren zu unvollstaendigen Familien. Nichts zu \u00fcbermitteln.',
            ]);
            return [
                'result' => 'no_pending',
                'list_id' => '',
                'shipment_count' => 0,
                'listPDF' => null,
                'submitted_order_ids' => [],
            ];
        }

        // Ab hier arbeiten wir nur noch mit den Sendungen, deren Familien
        // komplett sind.
        $pendingShipments = $completeShipments;

        // Pickup-Datum = heute (Abholung am selben Tag der Anmeldung)
        $borderoDate = date('Y-m-d');

        $this->getLogger(__METHOD__)->info('TranslandShipping::bordero.date_calculated', [
            'today' => date('l'),
            'calculated_pickup' => $borderoDate
        ]);
        $grouped = [];
        foreach ($pendingShipments as $shipment) {
            // Immer den frisch berechneten borderoDate verwenden.
            // Der gespeicherte pickup_date von der Registrierung ist
            // irrelevant — der Bordero bestimmt das Abholdatum.
            $grouped[$borderoDate][] = $shipment;
        }

        $allSubmittedOrderIds = [];
        $lastListId = '';
        $lastListPDF = null;
        $totalCount = 0;

        foreach ($grouped as $date => $shipments) {
            $listId = 'LIST-' . str_replace('-', '', $date) . '-' . time();
            $borderoPayload = $this->payloadBuilder->buildBorderoPayload($shipments, $date, $listId);

            try {
                $apiResponse = $this->apiService->submitShippingList($borderoPayload, $returnList);
            } catch (\Throwable $apiEx) {
                // FIX: get_class() und dynamische Exception-Logs vermeiden
                $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.apiException', [
                    'list_id' => $listId,
                    'exception' => $apiEx->getMessage()
                ]);
                continue;
            }

            // Sicherstellen, dass api_result ein String ist (kein dynamisches Object)
            $apiResult = (string) ($apiResponse['result'] ?? $apiResponse['status'] ?? 'MISSING');

            if ($apiResult !== 'ok') {
                $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.unexpectedResult', [
                    'list_id' => $listId
                ]);
                continue;
            }

            $submittedOrderIds = array_column($shipments, 'order_id');

            // Check auf PDF - Zugriff über Array-Key ist sicher
            $currentPDF = $apiResponse['listPDF'] ?? null;

            if (!empty($currentPDF)) {
                try {
                    /** @var \TranslandShipping\Services\EmailService $emailService */
                    $emailService = pluginApp(\TranslandShipping\Services\EmailService::class);
                    $emailService->sendLabelEmail((string) $currentPDF, 0);

                    $this->getLogger(__METHOD__)->info('TranslandShipping::bordero.mail_sent', [
                        'list_id' => $listId
                    ]);
                } catch (\Exception $e) {
                    $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.mail_failed', [
                        'error' => $e->getMessage()
                    ]);
                }
            } else {
                $this->getLogger(__METHOD__)->warning('TranslandShipping::bordero.no_pdf_in_response', [
                    'list_id' => $listId
                ]);
            }

            $this->storageService->markShipmentsAsSubmitted($submittedOrderIds, $listId);

            $allSubmittedOrderIds = array_merge($allSubmittedOrderIds, $submittedOrderIds);
            $lastListId = $listId;
            $lastListPDF = $currentPDF;
            $totalCount += count($shipments);
        }

        return [
            'result' => 'ok',
            'list_id' => $lastListId,
            'shipment_count' => $totalCount,
            'listPDF' => $lastListPDF,
            'submitted_order_ids' => $allSubmittedOrderIds,
        ];
    }
    public function getPendingShipments(string $date = ''): array
    {
        return $this->storageService->getPendingShipments($date);
    }

    public function storeShipmentAfterLabel(array $shipmentData): void
    {
        $this->storageService->storeShipment($shipmentData);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Familie-Vollstaendigkeitslogik
    //
    // Use case: Ein Hauptauftrag mit mehreren Lieferauftraegen (Kind-Orders),
    // bei dem mehrere Kinder das Spedition-Profil haben. Der Packer labelt
    // die Kinder in beliebiger Reihenfolge. Die Familie darf erst als Bordero
    // raus, wenn ALLE nicht-stornierten Spedition-Mitglieder (Parent + Kinder)
    // ein Label haben.
    //
    // DHL-Kinder und stornierte Kinder werden von der Vollstaendigkeits-
    // pruefung ausgeschlossen – sie muessen nicht gelabelt sein, damit die
    // Familie als komplett gilt.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Filters pending shipments to only those that belong to a complete family.
     *
     * @param array $pendingShipments  stored shipment records (each has order_id + parent_order_id)
     * @return array  subset of $pendingShipments whose family is complete
     */
    private function filterByCompleteFamilies(array $pendingShipments): array
    {
        // Gruppe pending Shipments nach family_id.
        // family_id = parent_order_id wenn gesetzt, sonst order_id (Einzelauftrag
        // = Familie der Groesse 1).
        $pendingByFamily = [];           // [family_id => [shipment, shipment, ...]]
        $pendingOrderIds = [];           // flat: order_id => true, fuer schnellen Lookup

        foreach ($pendingShipments as $shipment) {
            $orderId = (int) ($shipment['order_id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            $parentId = (int) ($shipment['parent_order_id'] ?? 0);
            $familyId = $parentId > 0 ? $parentId : $orderId;

            $pendingByFamily[$familyId][] = $shipment;
            $pendingOrderIds[$orderId] = true;
        }

        $completeShipments = [];

        foreach ($pendingByFamily as $familyId => $familyShipments) {
            // Required members = alle Spedition-Mitglieder der Familie bei Plenty
            // (nicht storniert). Das sind die, die ein Label haben MUESSEN.
            $requiredOrderIds = $this->getRequiredFamilyMemberIds($familyId);

            if (empty($requiredOrderIds)) {
                // Familie konnte nicht ermittelt werden (z.B. Plenty-Fehler beim
                // Laden). Safest: als unvollstaendig behandeln und im Log laut sein.
                $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.familyUnknown', [
                    'family_id' => $familyId,
                    'note'      => 'Konnte Familie-Mitglieder nicht aus Plenty laden – Familie wird als unvollstaendig behandelt und bleibt pending.',
                ]);
                continue;
            }

            // Pruefen ob fuer jedes required member ein pending Shipment da ist
            $missingOrderIds = [];
            foreach ($requiredOrderIds as $requiredId) {
                if (empty($pendingOrderIds[$requiredId])) {
                    $missingOrderIds[] = $requiredId;
                }
            }

            if (!empty($missingOrderIds)) {
                $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.familyIncomplete', [
                    'family_id'        => $familyId,
                    'required_members' => $requiredOrderIds,
                    'pending_members'  => array_keys(array_intersect_key(
                        $pendingOrderIds,
                        array_flip($requiredOrderIds)
                    )),
                    'missing_members'  => $missingOrderIds,
                    'note'             => 'Familie wartet noch auf Labels der fehlenden Mitglieder.',
                ]);
                continue;
            }

            // Familie ist komplett – alle Shipments dieser Familie freigeben
            $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.familyComplete', [
                'family_id'        => $familyId,
                'required_members' => $requiredOrderIds,
                'shipment_count'   => count($familyShipments),
            ]);
            foreach ($familyShipments as $s) {
                $completeShipments[] = $s;
            }
        }

        return $completeShipments;
    }

    /**
     * Returns the order IDs of all Plenty orders that belong to the given
     * family and MUST be labelled before the family can be submitted.
     *
     * "Required" means:
     *   - same family_id (parent_order_id or own id)
     *   - non-cancelled status
     *   - shipping profile is one of SPEDITION_PROFILE_IDS
     *
     * DHL/parcel-service children and cancelled orders are EXCLUDED – they
     * are not required to be labelled for the family to be considered complete.
     *
     * @return int[]  list of order ids that need to be labelled
     */
    private function getRequiredFamilyMemberIds(int $familyId): array
    {
        try {
            $parent = $this->orderRepository->findOrderById($familyId, [
                'addresses',
            ]);
        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.familyLoadFailed', [
                'family_id' => $familyId,
                'error'     => $e->getMessage(),
            ]);
            return [];
        }

        if (!$parent || !$parent->id) {
            return [];
        }

        $required = [];

        // Parent selbst pruefen
        if ($this->orderQualifiesForFamily($parent)) {
            $required[] = (int) $parent->id;
        }

        // Kinder durchgehen – childOrders wird lazy von Plenty geladen wenn
        // wir darauf zugreifen. Keine Garantie dass das in jeder Stable-7-
        // Version funktioniert, aber es ist der offiziell dokumentierte Weg
        // (Order::childOrders property).
        $children = [];
        try {
            $children = $parent->childOrders ?? [];
        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.childOrdersAccessFailed', [
                'family_id' => $familyId,
                'error'     => $e->getMessage(),
            ]);
            $children = [];
        }

        foreach ($children as $child) {
            if ($this->orderQualifiesForFamily($child)) {
                $required[] = (int) $child->id;
            }
        }

        return array_values(array_unique($required));
    }

    /**
     * Does this order count as a "required" family member?
     *
     * @param mixed $order  Plenty Order model
     */
    private function orderQualifiesForFamily($order): bool
    {
        if (!$order || empty($order->id)) {
            return false;
        }

        // Cancelled?
        $statusId = (float) ($order->statusId ?? 0);
        if (in_array($statusId, self::CANCELLED_STATUS_IDS, true)) {
            return false;
        }

        // Shipping profile must be a Spedition profile
        $profileId = (int) ($order->shippingProfileId ?? 0);
        return in_array($profileId, self::SPEDITION_PROFILE_IDS, true);
    }
}