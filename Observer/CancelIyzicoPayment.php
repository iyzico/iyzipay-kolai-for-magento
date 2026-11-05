<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Observer;

use Iyzico\Kolai\Model\Payment\CancelService;
use Iyzico\Kolai\Model\Payment\PaymentMethod;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;

/**
 * order_cancel_after: forwards the cancel of a Kolai order to iyzico.
 * The order is saved by whoever called cancel(), so comments added here persist.
 */
class CancelIyzicoPayment implements ObserverInterface
{
    private CancelService $cancelService;

    public function __construct(CancelService $cancelService)
    {
        $this->cancelService = $cancelService;
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof Order || !$order->getId()) {
            return;
        }

        $payment = $order->getPayment();
        if (!$payment || $payment->getMethod() !== PaymentMethod::CODE) {
            return;
        }

        $this->cancelService->cancel($order);
    }
}
