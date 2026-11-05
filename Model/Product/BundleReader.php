<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Product;

use Kolai\Support\Str;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;

/**
 * A bundle's DEFAULT selections as Kolai "bundledItems", and the bundle price
 * those defaults add up to.
 *
 * Defaults only: POST /orders adds a bundle with its default selections (Kolai
 * cannot choose options), so the advertised price must be that same basket —
 * otherwise the product page and the order total disagree.
 */
class BundleReader
{
    /** Magento\Bundle\Model\Product\Price::PRICE_TYPE_DYNAMIC, without a hard dependency on Magento_Bundle. */
    private const PRICE_TYPE_DYNAMIC = 0;

    /** Selection price type: 0 fixed amount, 1 percent of the bundle price. */
    private const SELECTION_PERCENT = 1;

    private PriceResolver $prices;

    private CatalogSupport $catalog;

    public function __construct(PriceResolver $prices, CatalogSupport $catalog)
    {
        $this->prices  = $prices;
        $this->catalog = $catalog;
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,price:?array<string,mixed>}
     *         `price` is null when the bundle has no default selection: the
     *         caller then falls back to the bundle's own price info.
     */
    public function read(Product $bundle): array
    {
        $type = $bundle->getTypeInstance();
        if (!method_exists($type, 'getOptionsCollection') || !method_exists($type, 'getSelectionsCollection')) {
            return ['items' => [], 'price' => null];
        }

        $optionIds = $type->getOptionsCollection($bundle)->getAllIds();
        if ($optionIds === []) {
            return ['items' => [], 'price' => null];
        }

        /** @var Collection $selections */
        $selections = $type->getSelectionsCollection($optionIds, $bundle);
        // The bundle type selects only quote-item attributes; the link, image
        // and special price window need these too.
        $selections->addAttributeToSelect($this->catalog->existingAttributes([
            'name', 'image', 'url_key', 'price', 'special_price', 'special_from_date', 'special_to_date', 'tax_class_id',
        ]));
        $this->catalog->addStock($selections);

        $dynamic    = (int) $bundle->getData('price_type') === self::PRICE_TYPE_DYNAMIC;
        $bundleBase = (float) $bundle->getData('price');

        $items     = [];
        $taxable   = !$dynamic && (int) $bundle->getData('tax_class_id') > 0;
        $totalIncl = $dynamic ? 0.0 : $this->prices->including($bundle, $bundleBase);
        $totalExcl = $dynamic ? 0.0 : $this->prices->excluding($bundle, $bundleBase);

        /** @var Product $selection */
        foreach ($selections as $selection) {
            if (!(int) $selection->getData('is_default')) {
                continue;
            }

            $quantity = max(1, (int) round((float) $selection->getData('selection_qty')));
            if ($dynamic) {
                $unitRaw    = $this->prices->raw($selection)['final'];
                $taxProduct = $selection;
            } else {
                $value      = (float) $selection->getData('selection_price_value');
                $unitRaw    = (int) $selection->getData('selection_price_type') === self::SELECTION_PERCENT
                    ? $bundleBase * $value / 100
                    : $value;
                $taxProduct = $bundle;
            }

            $pricedIndividually = $dynamic || $unitRaw > 0;
            $unitIncl           = $pricedIndividually ? $this->prices->including($taxProduct, $unitRaw) : 0.0;
            $unitExcl           = $pricedIndividually ? $this->prices->excluding($taxProduct, $unitRaw) : 0.0;

            $taxable    = $taxable || ($pricedIndividually && (int) $taxProduct->getData('tax_class_id') > 0);
            $totalIncl += $unitIncl * $quantity;
            $totalExcl += $unitExcl * $quantity;

            $item = [
                'id'    => (string) $selection->getId(),
                'title' => (string) $selection->getName(),
            ];
            if ((string) $selection->getSku() !== '') {
                $item['sku'] = (string) $selection->getSku();
            }
            $item['link'] = (string) $selection->getProductUrl();
            $imageUrl     = $this->catalog->imageUrl((string) $selection->getData('image'));
            if ($imageUrl !== null) {
                $item['imageLink'] = $imageUrl;
            }
            $item += [
                'quantity'           => $quantity,
                'price'              => Str::money($unitIncl),
                'pricedIndividually' => $pricedIndividually,
                'inStock'            => (bool) $selection->isSalable(),
            ];

            $items[] = $item;
        }

        if ($items === []) {
            return ['items' => [], 'price' => null];
        }

        $taxPrice = round($totalIncl - $totalExcl, 2);

        return [
            'items' => $items,
            'price' => [
                'price'          => round($totalIncl, 2),
                'sale_price'     => null,
                // Same rule as PriceResolver::fields(): "taxable", not "tax > 0".
                'included_tax'   => $taxable,
                'tax_price'      => $taxPrice,
                'tax_percentage' => $totalExcl > 0 ? round($taxPrice / $totalExcl * 100, 2) : 0.0,
            ],
        ];
    }
}
