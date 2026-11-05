<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Payment;

/**
 * The payment method every Kolai order carries. It is a gateway facade
 * (KolaiPaymentFacade in etc/di.xml) whose only command is refund; payment
 * itself happens inside the Kolai app and is reported back by PATCH /orders.
 */
final class PaymentMethod
{
    public const CODE = 'kolai';

    private function __construct()
    {
    }
}
