<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Product;

use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Model\Product;

/**
 * Turns Magento prices into the tax-inclusive figures plus tax breakdown that
 * Kolai\Mapper\ProductMapper expects (price, sale_price, included_tax,
 * tax_price, tax_percentage).
 *
 * Raw prices are read through the price info models, in the catalog's own tax
 * mode; CatalogHelper::getTaxPrice() then applies the store's "catalog prices
 * include tax" setting and rates — the same conversion the storefront does.
 */
class PriceResolver
{
    private const EPSILON = 0.005;

    private CatalogHelper $catalogHelper;

    public function __construct(CatalogHelper $catalogHelper)
    {
        $this->catalogHelper = $catalogHelper;
    }

    /**
     * @return array{regular:float,final:float}
     */
    public function raw(Product $product): array
    {
        $info    = $product->getPriceInfo();
        $regular = (float) $info->getPrice('regular_price')->getValue();
        $final   = (float) $info->getPrice('final_price')->getValue();

        return ['regular' => $regular, 'final' => $final > 0 ? $final : $regular];
    }

    /**
     * Neutral price fields for the mapper. $taxProduct decides the tax class;
     * for a configurable that is the child the price came from.
     *
     * @return array{price:float,sale_price:?float,included_tax:bool,tax_price:float,tax_percentage:float}
     */
    public function fields(Product $taxProduct, float $regular, float $final): array
    {
        // Only an ACTIVE, lower final price is a sale. A scheduled special price
        // never reaches $final, so it can never be advertised as live.
        $sale = ($final > 0 && $final < $regular - self::EPSILON) ? $final : null;

        if ((int) $taxProduct->getData('tax_class_id') <= 0) {
            return [
                'price'          => round($regular, 2),
                'sale_price'     => $sale !== null ? round($sale, 2) : null,
                'included_tax'   => false,
                'tax_price'      => 0.0,
                'tax_percentage' => 0.0,
            ];
        }

        $effective     = $sale ?? $regular;
        $effectiveIncl = $this->including($taxProduct, $effective);
        $effectiveExcl = $this->excluding($taxProduct, $effective);
        $taxPrice      = round($effectiveIncl - $effectiveExcl, 2);

        return [
            'price'          => round($this->including($taxProduct, $regular), 2),
            'sale_price'     => $sale !== null ? round($effectiveIncl, 2) : null,
            'included_tax'   => true,
            'tax_price'      => $taxPrice,
            'tax_percentage' => $effectiveExcl > 0 ? round($taxPrice / $effectiveExcl * 100, 2) : 0.0,
        ];
    }

    public function including(Product $product, float $raw): float
    {
        return (float) $this->catalogHelper->getTaxPrice($product, $raw, true);
    }

    public function excluding(Product $product, float $raw): float
    {
        return (float) $this->catalogHelper->getTaxPrice($product, $raw, false);
    }

    /**
     * The special price window, but only while the special price is what the
     * customer pays — a catalog rule or tier price can undercut it, and then
     * its dates say nothing about the advertised price.
     *
     * @return array{date_on_sale_from:?string,date_on_sale_to:?string}
     */
    public function saleWindow(Product $product, float $final): array
    {
        $none    = ['date_on_sale_from' => null, 'date_on_sale_to' => null];
        $special = $product->getPriceInfo()->getPrice('special_price')->getValue();

        if ($special === false || $special === null || abs((float) $special - $final) > self::EPSILON) {
            return $none;
        }

        $from = (string) $product->getData('special_from_date');
        $to   = (string) $product->getData('special_to_date');

        return [
            'date_on_sale_from' => $from !== '' ? gmdate('Y-m-d\T00:00:00+00:00', (int) strtotime($from)) : null,
            'date_on_sale_to'   => $to !== '' ? gmdate('Y-m-d\T23:59:59+00:00', (int) strtotime($to)) : null,
        ];
    }
}
