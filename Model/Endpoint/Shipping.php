<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Endpoint;

use Iyzico\Kolai\Model\Api\ApiRequest;
use Iyzico\Kolai\Model\Api\Result;
use Iyzico\Kolai\Model\Log\Logger;
use Iyzico\Kolai\Model\Quote\LineResolver;
use Iyzico\Kolai\Model\Quote\QuoteBuilder;
use Kolai\Address\AddressNormalizer;
use Kolai\Exception\Errors;
use Kolai\Exception\KolaiException;
use Kolai\Order\OrderRequestValidator;
use Magento\Tax\Helper\Data as TaxHelper;

/**
 * POST /kolai/v1/shipment-options (alias /shipping-options).
 *
 * Quotes shipping for the same lines POST /orders would receive, on a throwaway
 * quote, so the price offered here is the price the order gets.
 */
class Shipping
{
    private LineResolver $lineResolver;

    private QuoteBuilder $quoteBuilder;

    private TaxHelper $taxHelper;

    private Logger $logger;

    public function __construct(
        LineResolver $lineResolver,
        QuoteBuilder $quoteBuilder,
        TaxHelper $taxHelper,
        Logger $logger
    ) {
        $this->lineResolver = $lineResolver;
        $this->quoteBuilder = $quoteBuilder;
        $this->taxHelper    = $taxHelper;
        $this->logger       = $logger;
    }

    /**
     * @throws KolaiException
     */
    public function options(ApiRequest $request): Result
    {
        $body        = $request->body();
        $lines       = OrderRequestValidator::lines($body['products'] ?? null);
        $address     = is_array($body['address'] ?? null) ? $body['address'] : [];
        $destination = AddressNormalizer::minimalDestination($address);

        $this->logger->info('kolai.shipping: shipment options requested', [
            'lineCount' => count($lines),
            'country'   => $destination['country'],
            'state'     => $destination['state'],
        ]);

        $quote = $this->quoteBuilder->create($this->lineResolver->resolve($lines));

        try {
            if ($quote->isVirtual()) {
                throw Errors::noShippingOptions('No shippable products found');
            }

            $this->quoteBuilder->applyAddress($quote->getBillingAddress(), $destination);
            $this->quoteBuilder->applyAddress($quote->getShippingAddress(), $destination);
            $this->quoteBuilder->save($quote);

            $rates = $this->quoteBuilder->collectRates($quote);
            if ($rates === []) {
                $this->logger->warning('kolai.shipping: no shipping options', [
                    'country' => $destination['country'],
                    'state'   => $destination['state'],
                ]);
                throw Errors::noShippingOptions();
            }

            $shippingAddress = $quote->getShippingAddress();
            $options         = [];
            foreach ($rates as $code => $rate) {
                // Both directions through the tax helper, so "cost" is net and
                // "price" gross whether or not shipping prices include tax here.
                $price = (float) $rate->getPrice();
                $net   = round((float) $this->taxHelper->getShippingPrice($price, false, $shippingAddress), 2);
                $gross = round((float) $this->taxHelper->getShippingPrice($price, true, $shippingAddress), 2);

                $options[] = [
                    'id'       => $code,
                    'label'    => trim((string) $rate->getCarrierTitle() . ' - ' . (string) $rate->getMethodTitle(), ' -'),
                    'methodId' => (string) $rate->getCarrier(),
                    'cost'     => $net,
                    'tax'      => max(0.0, round($gross - $net, 2)),
                    'price'    => $gross,
                ];
            }

            return new Result(['options' => $options]);
        } finally {
            $this->quoteBuilder->discard($quote);
        }
    }
}
