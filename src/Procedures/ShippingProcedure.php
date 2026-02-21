<?php

namespace TranslandShipping\Procedures;

use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Modules\Order\Models\Order;
use Plenty\Plugin\Log\Loggable;
use TranslandShipping\Services\LabelService;

class ShippingProcedure
{
    use Loggable;

    /**
     * Wird von der Ereignisaktion aufgerufen.
     * Erstellt ein Transland-Label für den Auftrag.
     */
    public function run(EventProceduresTriggered $event): void
    {
        /** @var Order $order */
        $order = $event->getOrder();

        if (!$order || !$order->id) {
            $this->getLogger(__CLASS__)->error('TranslandShipping::ShippingProcedure', [
                'message' => 'Kein Auftrag gefunden'
            ]);
            return;
        }

        try {
            /** @var LabelService $labelService */
            $labelService = pluginApp(LabelService::class);
            $result = $labelService->createLabelForOrder($order->id);

            $this->getLogger(__CLASS__)->info('TranslandShipping::ShippingProcedure', [
                'orderId' => $order->id,
                'result'  => $result
            ]);

        } catch (\Exception $e) {
            $this->getLogger(__CLASS__)->error('TranslandShipping::ShippingProcedure', [
                'orderId' => $order->id,
                'error'   => $e->getMessage()
            ]);
        }
    }
}
