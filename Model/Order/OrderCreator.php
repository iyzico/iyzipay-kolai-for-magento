<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Order;

use Iyzico\Kolai\Model\Log\Logger;
use Iyzico\Kolai\Model\Payment\PaymentMethod;
use Iyzico\Kolai\Model\Quote\LineResolver;
use Iyzico\Kolai\Model\Quote\QuoteBuilder;
use Kolai\Address\AddressNormalizer;
use Kolai\Exception\Errors;
use Kolai\Exception\KolaiException;
use Kolai\Order\OrderMath;
use Kolai\Order\OrderRequestValidator;
use Kolai\Support\Str;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Store\Model\StoreManagerInterface;
use Throwable;

/**
 * POST /orders: Kolai payload -> guest quote -> Magento order in pending_payment.
 *
 * Nothing is written until OrderRequestValidator and the catalogue checks pass.
 * After that, two clean-up rules keep the store free of debris:
 *  - before the order exists, the temporary quote is deleted;
 *  - once it exists, it is CANCELED, not deleted — cancel releases the stock
 *    reservation through Magento's normal path, a raw delete would not, and
 *    deleting orders outside the admin needs the secure-area registry hack.
 *
 * The quote is a guest quote even when buyer.email belongs to a customer: a
 * customer quote makes Magento write the Kolai addresses into that customer's
 * address book on submit. The order is linked to the customer afterwards,
 * which is what WooCommerce does too.
 */
class OrderCreator
{
    private LineResolver $lineResolver;

    private QuoteBuilder $quoteBuilder;

    /** Concrete: submit() is not on CartManagementInterface. */
    private QuoteManagement $cartManagement;

    private OrderRepositoryInterface $orderRepository;

    private OrderManagementInterface $orderManagement;

    private CustomerRepositoryInterface $customerRepository;

    private StoreManagerInterface $storeManager;

    private KolaiOrderRepository $kolaiOrders;

    private OrderFactory $orderFactory;

    private Logger $logger;

    public function __construct(
        LineResolver $lineResolver,
        QuoteBuilder $quoteBuilder,
        QuoteManagement $cartManagement,
        OrderRepositoryInterface $orderRepository,
        OrderManagementInterface $orderManagement,
        CustomerRepositoryInterface $customerRepository,
        StoreManagerInterface $storeManager,
        KolaiOrderRepository $kolaiOrders,
        OrderFactory $orderFactory,
        Logger $logger
    ) {
        $this->lineResolver       = $lineResolver;
        $this->quoteBuilder       = $quoteBuilder;
        $this->cartManagement     = $cartManagement;
        $this->orderRepository    = $orderRepository;
        $this->orderManagement    = $orderManagement;
        $this->customerRepository = $customerRepository;
        $this->storeManager       = $storeManager;
        $this->kolaiOrders        = $kolaiOrders;
        $this->orderFactory       = $orderFactory;
        $this->logger             = $logger;
    }

    /**
     * @param array<string,mixed> $payload
     * @throws KolaiException
     */
    public function create(array $payload): Order
    {
        $lines    = OrderRequestValidator::validate($payload);
        $buyer    = (array) $payload['buyer'];
        $billing  = (array) ($payload['billingAddress'] ?? []);
        $shipping = (array) ($payload['shippingAddress'] ?? []);
        $email    = Str::email($buyer['email'] ?? '');
        if ($email === '') {
            throw Errors::invalidOrderRequest('buyer.email is invalid');
        }

        $this->logger->debug('kolai.order: payload validated', [
            'lineCount'   => count($lines),
            'hasDiscount' => isset($payload['discountAmount']),
        ]);

        $resolved = $this->lineResolver->resolve($lines);
        $quote    = $this->quoteBuilder->create($resolved);

        try {
            $this->prepareQuote($quote, $payload, $buyer, $billing, $shipping, $email);
            $order = $this->cartManagement->submit($quote);
        } catch (Throwable $e) {
            // submit() can fail AFTER the order row is committed (saving the
            // quote, a submit_success observer). Such an order must not linger.
            $placed = $this->placedOrder($quote);
            if ($placed !== null) {
                $this->cancelOrphan($placed, $e);
            } else {
                $this->quoteBuilder->discard($quote);
            }
            throw $this->translate($e);
        }

        if (!$order instanceof Order) {
            $this->quoteBuilder->discard($quote);
            throw Errors::internal('Order creation failed');
        }

        try {
            $this->finalize($order, $billing, $email);
        } catch (Throwable $e) {
            $this->cancelOrphan($order, $e);
            throw $this->translate($e);
        }

        $this->logger->info('kolai.order: order created', [
            'orderId' => (int) $order->getEntityId(),
            'total'   => round((float) $order->getGrandTotal(), 2),
            'status'  => (string) $order->getStatus(),
        ]);

        return $order;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $buyer
     * @param array<string,mixed> $billing
     * @param array<string,mixed> $shipping
     * @throws KolaiException
     * @throws LocalizedException
     */
    private function prepareQuote(
        Quote $quote,
        array $payload,
        array $buyer,
        array $billing,
        array $shipping,
        string $email
    ): void {
        $billingFields  = AddressNormalizer::orderAddress($billing, $buyer, true);
        $shippingFields = AddressNormalizer::orderAddress($shipping, $buyer, false);
        // Magento wants a telephone on the shipping address as well; it is the
        // same buyer's number, not new personal data.
        $shippingFields['phone'] = $billingFields['phone'];
        $shippingFields['email'] = $email;

        $taxId = trim((string) ($billing['taxId'] ?? ''));

        $quote->setCustomerEmail($email);
        $quote->setCustomerFirstname($billingFields['firstName']);
        $quote->setCustomerLastname($billingFields['lastName']);

        $this->quoteBuilder->applyAddress(
            $quote->getBillingAddress(),
            $billingFields,
            $taxId !== '' ? ['vat_id' => $taxId] : []
        );

        if (!$quote->isVirtual()) {
            $this->quoteBuilder->applyAddress($quote->getShippingAddress(), $shippingFields);
        }

        $this->quoteBuilder->save($quote);

        if (!$quote->isVirtual()) {
            $optionId = (string) $payload['shipmentOptionId'];
            $rates    = $this->quoteBuilder->collectRates($quote);
            if (!isset($rates[$optionId])) {
                throw Errors::invalidShipmentOption('Invalid shipment option');
            }
            $quote->getShippingAddress()->setShippingMethod($optionId);
        }

        // importData() checks the method is available. Its collectTotals() is a
        // no-op here — collectRates() already set the collected flag — so the
        // chosen shipping method is priced only by the explicit recollect below.
        $quote->getPayment()->importData(['method' => PaymentMethod::CODE]);
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $this->quoteBuilder->assertNoItemErrors($quote);

        if (isset($payload['discountAmount']) && $payload['discountAmount'] !== null) {
            $this->applyDiscount($quote, $payload['discountAmount']);
        }

        $this->quoteBuilder->save($quote);
    }

    /**
     * Applied as one order-level, non-taxable amount (the kolai_discount quote
     * total), so the total drops by exactly what Kolai asked — no per-item
     * distribution that rounding could shift by a kurus.
     *
     * @param mixed $requested
     * @throws KolaiException
     */
    private function applyDiscount(Quote $quote, $requested): void
    {
        $before   = (float) $quote->getGrandTotal();
        $discount = OrderMath::discount($before, $requested);
        $rate     = (float) $quote->getBaseToQuoteRate() ?: 1.0;

        $quote->setKolaiDiscountAmount(-$discount);
        $quote->setBaseKolaiDiscountAmount(-round($discount / $rate, 4));
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();

        $drift = OrderMath::discountDrift($before, (float) $quote->getGrandTotal(), $discount);
        if (abs($drift) > 0.01) {
            $this->logger->warning('kolai.order: discount invariant drift detected', [
                'totalBefore' => round($before, 2),
                'totalAfter'  => round((float) $quote->getGrandTotal(), 2),
                'discount'    => $discount,
                'drift'       => $drift,
            ]);
        }
    }

    /**
     * @param array<string,mixed> $billing
     */
    private function finalize(Order $order, array $billing, string $email): void
    {
        $status = Order::STATE_PENDING_PAYMENT;
        $order->setState(Order::STATE_PENDING_PAYMENT)->setStatus($status);
        $this->linkCustomer($order, $email);
        $this->orderRepository->save($order);

        $invoiceType = strtolower(trim((string) ($billing['invoiceType'] ?? '')));
        $taxId       = trim((string) ($billing['taxId'] ?? ''));
        $taxOffice   = Str::clean($billing['taxOffice'] ?? '');

        $fields = array_filter([
            'invoice_type' => $invoiceType,
            'tax_id'       => $taxId,
            'tax_office'   => $taxOffice,
        ], 'strlen');

        if ($fields !== []) {
            $this->kolaiOrders->save((int) $order->getEntityId(), $fields);
        }
    }

    private function linkCustomer(Order $order, string $email): void
    {
        try {
            $customer = $this->customerRepository->get(
                $email,
                (int) $this->storeManager->getStore()->getWebsiteId()
            );
        } catch (NoSuchEntityException $e) {
            return;
        } catch (LocalizedException $e) {
            return;
        }

        $order->setCustomerId((int) $customer->getId());
        $order->setCustomerIsGuest(0);
        $order->setCustomerGroupId((int) $customer->getGroupId());
    }

    private function placedOrder(Quote $quote): ?Order
    {
        $incrementId = (string) $quote->getReservedOrderId();
        if ($incrementId === '') {
            return null;
        }

        try {
            $order = $this->orderFactory->create()->loadByIncrementIdAndStoreId($incrementId, (int) $quote->getStoreId());
        } catch (Throwable $e) {
            return null;
        }

        return $order->getEntityId() ? $order : null;
    }

    private function cancelOrphan(Order $order, Throwable $cause): void
    {
        $this->logger->error('kolai.order: order finalisation failed, canceling', [
            'orderId'   => (int) $order->getEntityId(),
            'exception' => $cause,
        ]);

        try {
            $this->orderManagement->cancel((int) $order->getEntityId());
        } catch (Throwable $e) {
            $this->logger->error('kolai.order: could not cancel orphan order', [
                'orderId'   => (int) $order->getEntityId(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Kolai errors pass through. A Magento refusal at this stage (minimum order
     * amount, a carrier rejecting the address, …) is a bad request, not a crash;
     * its message is Magento's own customer-facing text, safe to return.
     */
    private function translate(Throwable $e): Throwable
    {
        if ($e instanceof KolaiException) {
            return $e;
        }
        if ($e instanceof LocalizedException) {
            $this->logger->warning('kolai.order: Magento refused the order', ['exception' => $e]);

            return Errors::invalidOrderRequest($e->getMessage());
        }

        return $e;
    }
}
