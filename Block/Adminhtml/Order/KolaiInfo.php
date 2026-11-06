<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Block\Adminhtml\Order;

use Iyzico\Kolai\Model\Order\KolaiOrderRepository;
use Iyzico\Kolai\Model\Payment\PaymentMethod;
use Iyzico\Kolai\Model\Payment\RefundRecord;
use Iyzico\Kolai\Model\Payment\RefundService;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order;

/**
 * Kolai box in the admin order view: what iyzico knows about this order and
 * what the refund ledger says has gone back. The place to look before
 * re-running a refund that failed half way.
 */
class KolaiInfo extends Template
{
    /** Operation statuses that need a human. */
    public const ATTENTION_STATUSES = [
        'partial', 'needs_reconciliation', 'abandoned', 'failed', RefundService::STATUS_AWAITING_CREDIT_MEMO,
    ];

    private Registry $registry;

    private KolaiOrderRepository $repository;

    private RefundRecord $refundRecord;

    /** @var array<string,mixed>|null */
    private ?array $data = null;

    public function __construct(
        Context $context,
        Registry $registry,
        KolaiOrderRepository $repository,
        RefundRecord $refundRecord,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->registry     = $registry;
        $this->repository   = $repository;
        $this->refundRecord = $refundRecord;
    }

    public function getOrder(): ?Order
    {
        $order = $this->registry->registry('current_order') ?: $this->registry->registry('sales_order');

        return $order instanceof Order ? $order : null;
    }

    public function isKolaiOrder(): bool
    {
        $order = $this->getOrder();

        return $order !== null && $order->getPayment() !== null
            && $order->getPayment()->getMethod() === PaymentMethod::CODE;
    }

    /** @return array<string,mixed> */
    public function getKolaiData(): array
    {
        if ($this->data === null) {
            $order      = $this->getOrder();
            $this->data = $order !== null ? $this->repository->get((int) $order->getId()) : [];
        }

        return $this->data;
    }

    /**
     * itemTransactions joined with the refund ledger.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getTransactionRows(): array
    {
        $data   = $this->getKolaiData();
        $ledger = (array) ($data['refunded_transactions'] ?? []);
        $rows   = [];
        foreach ((array) ($data['item_transactions'] ?? []) as $transaction) {
            if (!is_array($transaction)) {
                continue;
            }
            $id       = (string) ($transaction['paymentTransactionId'] ?? '');
            $paid     = (float) ($transaction['paidPrice'] ?? 0);
            $refunded = (float) ($ledger[$id] ?? 0);
            $rows[]   = [
                'itemId'               => (string) ($transaction['itemId'] ?? ''),
                'paymentTransactionId' => $id,
                'transactionStatus'    => $transaction['transactionStatus'] ?? null,
                'price'                => (float) ($transaction['price'] ?? 0),
                'paidPrice'            => $paid,
                'refunded'             => $refunded,
                'refundable'           => max(0.0, round($paid - $refunded, 2)),
            ];
        }

        return $rows;
    }

    /**
     * Newest first. An operation still "awaiting_credit_memo" in storage is
     * shown as what the database now says it is (display only; the next
     * refund persists it).
     *
     * @return array<int,array<string,mixed>>
     */
    public function getRefundOperations(): array
    {
        $data = $this->getKolaiData();
        if ($data === []) {
            return [];
        }

        return array_reverse($this->refundRecord->settle((int) $this->getOrder()->getId(), $data));
    }

    public function isAwaitingCreditMemo(string $status): bool
    {
        return $status === RefundService::STATUS_AWAITING_CREDIT_MEMO;
    }

    public function needsAttention(string $status): bool
    {
        return in_array($status, self::ATTENTION_STATUSES, true);
    }

    /** @return array<string,mixed> */
    public function getCancelResult(): array
    {
        return (array) ($this->getKolaiData()['cancel_result'] ?? []);
    }

    public function formatAmount(float $amount): string
    {
        $order = $this->getOrder();

        return $order !== null ? (string) $order->formatPriceTxt($amount) : number_format($amount, 2, '.', '');
    }

    protected function _toHtml(): string
    {
        return $this->isKolaiOrder() ? parent::_toHtml() : '';
    }
}
