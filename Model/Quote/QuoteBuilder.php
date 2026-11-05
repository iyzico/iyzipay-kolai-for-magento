<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Quote;

use Iyzico\Kolai\Model\Config;
use Iyzico\Kolai\Model\Log\Logger;
use Kolai\Exception\Errors;
use Kolai\Exception\KolaiException;
use Magento\Catalog\Model\Product;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\Store\Model\StoreManagerInterface;
use Throwable;

/**
 * Builds the quote behind both a shipment-options quote and an order.
 *
 * The quote is a guest quote and inactive from birth, so it can never surface as
 * someone's storefront cart. Prices and tax are therefore the guest group's —
 * the same figures GET /products reports, which is what Kolai showed the buyer.
 *
 * Shipping rates come from Magento's own carriers through collectTotals(), so a
 * quote and the order built from the same lines price shipping identically.
 */
class QuoteBuilder
{
    private QuoteFactory $quoteFactory;

    private QuoteResource $quoteResource;

    private StoreManagerInterface $storeManager;

    private RegionResolver $regionResolver;

    private Config $config;

    private Logger $logger;

    public function __construct(
        QuoteFactory $quoteFactory,
        QuoteResource $quoteResource,
        StoreManagerInterface $storeManager,
        RegionResolver $regionResolver,
        Config $config,
        Logger $logger
    ) {
        $this->quoteFactory   = $quoteFactory;
        $this->quoteResource  = $quoteResource;
        $this->storeManager   = $storeManager;
        $this->regionResolver = $regionResolver;
        $this->config         = $config;
        $this->logger         = $logger;
    }

    /**
     * In memory only; call save() once addresses are set.
     *
     * @param array<int,array{product:Product,request:\Magento\Framework\DataObject,productId:int,quantity:int}> $lines
     * @throws KolaiException
     */
    public function create(array $lines): Quote
    {
        /** @var \Magento\Store\Model\Store $store */
        $store = $this->storeManager->getStore();

        /** @var Quote $quote */
        $quote = $this->quoteFactory->create();
        $quote->setStore($store);
        $quote->setIsActive(false);
        $quote->setCustomerIsGuest(true);
        $quote->setCustomerGroupId(GroupInterface::NOT_LOGGED_IN_ID);

        foreach ($lines as $line) {
            $this->add($quote, $line['product'], $line['request']);
        }

        return $quote;
    }

    /**
     * @param array<string,string> $fields Neutral keys from Kolai\Address\AddressNormalizer:
     *        country, state, city, postcode, addressLine1/2, and optionally
     *        firstName, lastName, company, email, phone.
     * @param array<string,mixed>  $extra  Raw Magento address fields (vat_id, telephone, …).
     */
    public function applyAddress(Address $address, array $fields, array $extra = []): void
    {
        $country = (string) ($fields['country'] ?? '');
        $region  = $this->regionResolver->resolve($country, (string) ($fields['state'] ?? ''));
        $street  = array_values(array_filter([
            (string) ($fields['addressLine1'] ?? ''),
            (string) ($fields['addressLine2'] ?? ''),
        ], 'strlen'));

        $address->addData([
            'firstname'            => (string) ($fields['firstName'] ?? ''),
            'lastname'             => (string) ($fields['lastName'] ?? ''),
            'company'              => (string) ($fields['company'] ?? ''),
            'street'               => $street === [] ? '' : implode("\n", $street),
            'city'                 => (string) ($fields['city'] ?? ''),
            'region_id'            => $region['region_id'],
            'region'               => $region['region'],
            'postcode'             => (string) ($fields['postcode'] ?? ''),
            'country_id'           => $country,
            'telephone'            => (string) ($fields['phone'] ?? ''),
            'email'                => (string) ($fields['email'] ?? ''),
            'save_in_address_book' => 0,
            'same_as_billing'      => 0,
            // The payload was validated by kolai-php already. Magento's own rules
            // (zip required for TR, …) would reject addresses the Kolai app
            // legitimately collects without a postcode.
            'should_ignore_validation' => true,
        ] + $extra);
    }

    public function save(Quote $quote): void
    {
        $this->quoteResource->save($quote);
    }

    /**
     * Rates for the quote's shipping address, keyed by rate code (carrier_method),
     * limited to the methods the admin allows for Kolai.
     *
     * @return array<string,Rate>
     */
    public function collectRates(Quote $quote): array
    {
        $address = $quote->getShippingAddress();
        $address->setCollectShippingRates(true);
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();

        $allowed = $this->config->getAllowedShippingMethods();
        $rates   = [];
        foreach ($address->getAllShippingRates() as $rate) {
            /** @var Rate $rate */
            if ($rate->isDeleted() || $rate->getErrorMessage()) {
                continue;
            }
            $code = (string) $rate->getCode();
            if ($allowed !== [] && !in_array($code, $allowed, true)) {
                continue;
            }
            $rates[$code] = $rate;
        }

        return $rates;
    }

    /**
     * Throws when collectTotals() left an item with a stock or other error.
     *
     * @throws KolaiException
     */
    public function assertNoItemErrors(Quote $quote): void
    {
        if (!$quote->getHasError()) {
            return;
        }
        if ($this->hasStockError($quote)) {
            throw Errors::insufficientStock('Insufficient stock quantity');
        }

        $messages = [];
        foreach ($quote->getErrors() as $error) {
            $messages[] = (string) $error->getText();
        }

        throw Errors::invalidOrderRequest($messages !== [] ? implode(' ', $messages) : 'Order could not be built');
    }

    /** A throwaway quote (shipment-options) or one whose order failed. */
    public function discard(Quote $quote): void
    {
        if (!$quote->getId()) {
            return;
        }
        try {
            $this->quoteResource->delete($quote);
        } catch (Throwable $e) {
            $this->logger->warning('kolai.order: could not delete temporary quote', [
                'quoteId'   => (int) $quote->getId(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * @throws KolaiException
     */
    private function add(Quote $quote, Product $product, \Magento\Framework\DataObject $request): void
    {
        if (!$product->isSalable()) {
            throw Errors::insufficientStock('Product is out of stock');
        }

        $isBundle = $product->getTypeId() === 'bundle';
        // Keep a failing item in the quote so its error infos can tell a stock
        // shortage apart from any other refusal.
        $request->setData('add_to_cart_invalid_product', true);

        try {
            $result = $quote->addProduct($product, $request);
        } catch (LocalizedException $e) {
            if ($this->hasStockError($quote)) {
                throw Errors::insufficientStock('Insufficient stock quantity');
            }
            throw Errors::invalidProductList(
                ($isBundle ? 'Bundle product could not be added: ' : 'Product could not be added: ') . $e->getMessage()
            );
        }

        if (is_string($result)) {
            throw Errors::invalidProductList(
                ($isBundle ? 'Bundle product could not be added: ' : 'Product could not be added: ') . $result
            );
        }
    }

    private function hasStockError(Quote $quote): bool
    {
        foreach ($quote->getAllItems() as $item) {
            foreach ((array) $item->getErrorInfos() as $info) {
                if (($info['origin'] ?? null) === 'cataloginventory') {
                    return true;
                }
            }
        }

        return false;
    }
}
