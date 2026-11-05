<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Product;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Filter\TranslitUrl;

/**
 * Configurable product children as Kolai "variations", plus the configurable
 * attributes as "taxonomy" attributes.
 */
class VariationReader
{
    public const MAX_VARIATIONS = 100;

    private const CHILD_ATTRIBUTES = [
        'name', 'price', 'special_price', 'special_from_date', 'special_to_date',
        'tax_class_id', 'image', 'image_label', 'status', 'url_key',
    ];

    private CollectionFactory $collectionFactory;

    private PriceResolver $prices;

    private CatalogSupport $catalog;

    private TranslitUrl $translit;

    public function __construct(
        CollectionFactory $collectionFactory,
        PriceResolver $prices,
        CatalogSupport $catalog,
        TranslitUrl $translit
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->prices            = $prices;
        $this->catalog           = $catalog;
        $this->translit          = $translit;
    }

    /**
     * @return array<int,array{code:string,label:string,options:array<string,string>}>
     *         attribute id => code, label, option id => option label
     */
    public function configurableAttributes(Product $product): array
    {
        $type = $product->getTypeInstance();
        if (!$type instanceof Configurable) {
            return [];
        }

        $attributes = [];
        foreach ($type->getConfigurableAttributesAsArray($product) as $attributeId => $attribute) {
            $options = [];
            foreach ((array) ($attribute['values'] ?? []) as $value) {
                if (isset($value['value_index'])) {
                    $options[(string) $value['value_index']] = (string) ($value['store_label'] ?? $value['label'] ?? '');
                }
            }
            $attributes[(int) $attributeId] = [
                'code'    => (string) $attribute['attribute_code'],
                'label'   => (string) ($attribute['store_label'] ?: $attribute['label']),
                'options' => $options,
            ];
        }

        return $attributes;
    }

    /**
     * @param array<int,array{code:string,label:string,options:array<string,string>}> $attributes
     * @return array<int,array<string,mixed>>
     */
    public function taxonomyAttributes(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $attribute) {
            $options = [];
            foreach ($attribute['options'] as $id => $label) {
                $options[] = ['id' => (int) $id, 'name' => $label, 'slug' => $this->translit->filter($label)];
            }
            $out[] = [
                'name'    => $attribute['label'],
                'slug'    => $attribute['code'],
                'type'    => 'taxonomy',
                'visible' => true,
                'options' => $options,
            ];
        }

        return $out;
    }

    /**
     * Enabled children, capped at MAX_VARIATIONS. Cutting is announced with the
     * ['_truncated' => true, '_max' => N] sentinel the mapper understands, so a
     * short list is never mistaken for a product with few variants.
     *
     * @param array<int,array{code:string,label:string,options:array<string,string>}> $attributes
     * @return array{variations:array<int,array<string,mixed>>,children:Product[],truncated:bool,total:int}
     */
    public function variations(Product $product, array $attributes): array
    {
        $type = $product->getTypeInstance();
        if (!$type instanceof Configurable) {
            return ['variations' => [], 'children' => [], 'truncated' => false, 'total' => 0];
        }

        $childIds = array_map('intval', array_values((array) ($type->getChildrenIds($product->getId())[0] ?? [])));
        sort($childIds);
        $total     = count($childIds);
        $truncated = $total > self::MAX_VARIATIONS;
        $childIds  = array_slice($childIds, 0, self::MAX_VARIATIONS);

        if ($childIds === []) {
            return ['variations' => [], 'children' => [], 'truncated' => false, 'total' => 0];
        }

        $codes = array_unique(array_merge(
            self::CHILD_ATTRIBUTES,
            array_column($attributes, 'code')
        ));

        $collection = $this->collectionFactory->create();
        $collection->addIdFilter($childIds)
            ->addStoreFilter($this->catalog->storeId())
            ->addAttributeToSelect($this->catalog->existingAttributes($codes))
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->setOrder('entity_id', 'ASC');
        $this->catalog->addStock($collection);

        $variations = [];
        $children   = [];
        /** @var Product $child */
        foreach ($collection as $child) {
            $raw    = $this->prices->raw($child);
            $values = [];
            $labels = [];
            foreach ($attributes as $attributeId => $attribute) {
                $optionId = (string) $child->getData($attribute['code']);
                $label    = $attribute['options'][$optionId] ?? '';
                $values[] = [
                    'id'    => (int) $optionId,
                    'name'  => $attribute['label'],
                    'slug'  => $attribute['code'],
                    'value' => $label,
                ];
                if ($label !== '') {
                    $labels[] = $label;
                }
            }

            $row = [
                'id'          => (int) $child->getId(),
                'sku'         => (string) $child->getSku(),
                'description' => implode(' / ', $labels),
                'in_stock'    => (bool) $child->isSalable(),
                'attributes'  => $values,
            ] + $this->prices->fields($child, $raw['regular'], $raw['final']);

            $imageUrl = $this->catalog->imageUrl((string) $child->getData('image'));
            if ($imageUrl !== null) {
                $row['image'] = [
                    'url' => $imageUrl,
                    'alt' => (string) ($child->getData('image_label') ?: $child->getName()),
                ];
            }

            $variations[] = $row;
            $children[]   = $child;
        }

        if ($truncated) {
            $variations[] = ['_truncated' => true, '_max' => self::MAX_VARIATIONS];
        }

        return ['variations' => $variations, 'children' => $children, 'truncated' => $truncated, 'total' => $total];
    }

    /**
     * The child a configurable's headline price comes from: the cheapest
     * salable one, else the cheapest at all.
     *
     * @param Product[] $children
     */
    public function cheapest(array $children): ?Product
    {
        $best         = null;
        $bestPrice    = INF;
        $bestSalable  = false;
        foreach ($children as $child) {
            $price   = $this->prices->raw($child)['final'];
            $salable = (bool) $child->isSalable();
            if (($salable && !$bestSalable) || ($salable === $bestSalable && $price < $bestPrice)) {
                $best        = $child;
                $bestPrice   = $price;
                $bestSalable = $salable;
            }
        }

        return $best;
    }
}
