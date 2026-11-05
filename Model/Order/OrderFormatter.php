<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Order;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;

/**
 * The order DTO Kolai reads back from create, GET and PATCH.
 *
 * orderExpireAt mirrors WooCommerce's "hold stock" window with Magento's own
 * equivalent: sales/orders/delete_pending_after, after which the
 * sales_clean_orders cron cancels a still pending_payment order and releases
 * its stock.
 */
class OrderFormatter
{
    public const DEFAULT_LIFETIME_MINUTES = 480;

    private ScopeConfigInterface $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(Order $order): array
    {
        return [
            'orderId'       => (int) $order->getEntityId(),
            'orderNumber'   => (string) $order->getIncrementId(),
            'status'        => (string) $order->getStatus(),
            'total'         => round((float) $order->getGrandTotal(), 2),
            'currency'      => (string) $order->getOrderCurrencyCode(),
            'paymentMethod' => $order->getPayment() ? (string) $order->getPayment()->getMethod() : '',
            'orderExpireAt' => $this->expiresAt($order),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function detail(Order $order): array
    {
        return $this->summary($order) + [
            'dateCreated'  => self::iso((string) $order->getCreatedAt()),
            'dateModified' => self::iso((string) $order->getUpdatedAt()),
        ];
    }

    public function lifetimeMinutes(?int $storeId): int
    {
        $minutes = (int) $this->scopeConfig->getValue(
            'sales/orders/delete_pending_after',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $minutes < 1 ? self::DEFAULT_LIFETIME_MINUTES : $minutes;
    }

    private function expiresAt(Order $order): string
    {
        // A freshly placed order may not have created_at loaded back yet; it
        // was created this instant.
        $created = self::timestamp((string) $order->getCreatedAt()) ?? time();

        return gmdate('c', $created + $this->lifetimeMinutes((int) $order->getStoreId()) * 60);
    }

    /** sales_order timestamps are stored in UTC without a zone. */
    private static function timestamp(string $value): ?int
    {
        if ($value === '') {
            return null;
        }
        $time = strtotime($value . ' UTC');

        return $time === false ? null : $time;
    }

    private static function iso(string $value): ?string
    {
        $time = self::timestamp($value);

        return $time === null ? null : gmdate('c', $time);
    }
}
