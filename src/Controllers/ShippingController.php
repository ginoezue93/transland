<?php

namespace TranslandShipping\Controllers;

use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Order\Shipping\Information\Contracts\ShippingInformationRepositoryContract;
use Plenty\Modules\Order\Shipping\Package\Contracts\OrderShippingPackageRepositoryContract;
use Plenty\Modules\Order\Shipping\PackageType\Contracts\ShippingPackageTypeRepositoryContract;
use Plenty\Modules\Plugin\Storage\Contracts\StorageRepositoryContract;
use Plenty\Modules\Tag\Contracts\TagRepositoryContract;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Log\Loggable;
use TranslandShipping\Services\LabelService;
use TranslandShipping\Services\ShippingListService;
use TranslandShipping\Services\SettingsService;
use TranslandShipping\Services\StorageService;

class ShippingController extends Controller
{
    use Loggable;

    private const SPEDITION_PROFILE_IDS = [8, 95, 122, 124];
    private const TARGET_SHIPPING_PROFILE_ID = 111;
    private const TARGET_ORDER_STATUS = 7.0;
    private const TAG_AUT_ANMELDUNG = 'Aut. Anmeldung';
    private const TAG_GEFAHRSTOFF = 'Gefahrenstoff';

    private array $createOrderResult = [];

    private Request $request;
    private OrderRepositoryContract $orderRepository;
    private OrderShippingPackageRepositoryContract $orderShippingPackage;
    private ShippingInformationRepositoryContract $shippingInformation;
    private StorageRepositoryContract $storageRepository;
    private ShippingPackageTypeRepositoryContract $packageTypeRepository;
    private LabelService $labelService;
    private ShippingListService $shippingListService;
    private SettingsService $settingsService;
    private StorageService $storageService;

    public function __construct(
        Request $request,
        OrderRepositoryContract $orderRepository,
        OrderShippingPackageRepositoryContract $orderShippingPackage,
        ShippingInformationRepositoryContract $shippingInformation,
        StorageRepositoryContract $storageRepository,
        ShippingPackageTypeRepositoryContract $packageTypeRepository,
        LabelService $labelService,
        ShippingListService $shippingListService,
        SettingsService $settingsService,
        StorageService $storageService
    ) {
        $this->request = $request;
        $this->orderRepository = $orderRepository;
        $this->orderShippingPackage = $orderShippingPackage;
        $this->shippingInformation = $shippingInformation;
        $this->storageRepository = $storageRepository;
        $this->packageTypeRepository = $packageTypeRepository;
        $this->labelService = $labelService;
        $this->shippingListService = $shippingListService;
        $this->settingsService = $settingsService;
        $this->storageService = $storageService;
    }

    public function registerShipments(Request $request, $orderIds): array
    {
        $orderIds = $this->getOrderIds($request, $orderIds);
        $orderIds = $this->getOpenOrderIds($orderIds);
        $shipmentDate = date('Y-m-d');

        foreach ($orderIds as $orderId) {
            $orderId = (int)$orderId;

            try {
                $order = $this->orderRepository->findOrderById($orderId, [
                    'addresses', 'amounts', 'tags', 'properties',
                ]);

                if (!$order || !$order->id) {
                    $this->createOrderResult[$orderId] = $this->buildResultArray(false, 'Order not found', false, []);
                    continue;
                }

                if (!$this->hasSpeditionProfile($order)) {
                    $this->createOrderResult[$orderId] = $this->buildResultArray(false, 'Wrong shipping profile', false, []);
                    continue;
                }

                $hasHazmat = $this->hasTag($order, self::TAG_GEFAHRSTOFF);

                $plentyPackages = $this->orderShippingPackage->listOrderShippingPackages($orderId);
                if (empty($plentyPackages)) {
                    $this->createOrderResult[$orderId] = $this->buildResultArray(false, 'No packages', false, []);
                    continue;
                }

                $packages = $this->buildPackagesFromVersandcenter($plentyPackages, $hasHazmat);
                $orderArray = $this->orderToArray($order);

                $result = $this->labelService->createLabelForOrder($orderArray, $packages, 'ZPL', []);

                if (empty($result['label_data'])) {
                    $this->createOrderResult[$orderId] = $this->buildResultArray(false, 'No label from API', false, []);
                    continue;
                }

                $sscc = $result['sscc_list'][0] ?? ('transland-' . $orderId);
                $storageKey = $sscc . '.zpl';

                $storageObject = $this->storageRepository->uploadObject(
                    'TranslandShipping',
                    $storageKey,
                    $result['label_data']
                );

                $labelUrl = $this->resolveLabelUrl('TranslandShipping', $storageKey, $storageObject);

                $shipmentItems = [];

                foreach ($plentyPackages as $idx => $plentyPkg) {
                    $pkgSscc = $result['packages'][$idx]['sscc'] ?? $sscc;

                    $this->orderShippingPackage->updateOrderShippingPackage(
                        $plentyPkg->id,
                        [
                            'packageNumber' => $pkgSscc,
                            'label' => $labelUrl,
                        ]
                    );

                    $shipmentItems[] = [
                        'labelUrl' => $labelUrl,
                        'shipmentNumber' => $pkgSscc,
                    ];
                }

                $this->saveShippingInformation($orderId, $shipmentDate, $shipmentItems);
                $this->runPostLabelActions($order);

                $this->createOrderResult[$orderId] = $this->buildResultArray(
                    true,
                    'OK',
                    false,
                    $shipmentItems
                );

            } catch (\Exception $e) {
                $this->createOrderResult[$orderId] = $this->buildResultArray(false, $e->getMessage(), false, []);
            }
        }

        return $this->createOrderResult;
    }

    private function resolveLabelUrl(string $plugin, string $storageKey, $storageObject): string
    {
        try {
            $url = $this->storageRepository->getObjectUrl($plugin, $storageKey, false, 60);
            if (is_string($url) && strpos($url, 'http') === 0) {
                return $url;
            }
        } catch (\Throwable $e) {}

        if (!empty($storageObject->url)) return $storageObject->url;
        if (!empty($storageObject->publicUrl)) return $storageObject->publicUrl;
        if (!empty($storageObject->objectUrl)) return $storageObject->objectUrl;

        return $storageObject->key ?? $storageKey;
    }

    private function hasSpeditionProfile($order): bool
    {
        return in_array((int)($order->shippingProfileId ?? 0), self::SPEDITION_PROFILE_IDS, true);
    }

    private function hasTag($order, string $tagName): bool
    {
        foreach ($order->tags ?? [] as $tag) {
            $name = $tag->tagName ?? ($tag->name ?? '');
            if (trim($name) === $tagName) return true;
        }
        return false;
    }

    private function buildPackagesFromVersandcenter(array $plentyPackages, bool $hasHazmat): array
    {
        return array_map(function ($pkg) use ($hasHazmat) {
            return [
                'content' => 'Waren',
                'packaging_type' => $pkg->packageTypeName ?? '',
                'length_cm' => (int)($pkg->length ?? 0),
                'width_cm' => (int)($pkg->width ?? 0),
                'height_cm' => (int)($pkg->height ?? 0),
                'weight_gr' => (int)($pkg->weight ?? 0),
                'dangerous_goods' => $hasHazmat ? [] : null,
            ];
        }, $plentyPackages);
    }

    private function saveShippingInformation(int $orderId, string $shipmentDate, array $shipmentItems): void
    {
        $this->shippingInformation->saveShippingInformation([
            'orderId' => $orderId,
            'shippingServiceProvider' => 'TranslandShipping',
            'shippingStatus' => 'registered',
            'additionalData' => $shipmentItems,
        ]);
    }

    private function getOrderIds(Request $request, $orderIds): array
    {
        return is_array($orderIds) ? $orderIds : [$orderIds];
    }

    private function getOpenOrderIds(array $orderIds): array
    {
        return $orderIds;
    }

    private function orderToArray($order): array
    {
        return ['id' => $order->id];
    }

    private function buildResultArray(bool $success, string $message, bool $newPackage, array $shipmentItems): array
    {
        return [
            'success' => $success,
            'message' => $message,
            'newPackagenumber' => $newPackage,
            'packages' => $shipmentItems,
        ];
    }

    private function runPostLabelActions($order): void
    {
        $this->orderRepository->updateOrder(['statusId' => self::TARGET_ORDER_STATUS], $order->id);
    }
}