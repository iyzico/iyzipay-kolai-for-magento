<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Quote;

use Kolai\Exception\Errors;
use Kolai\Exception\KolaiException;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\Product\Visibility;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Turns validated Kolai order lines into what Quote::addProduct() takes: the
 * product to add and its buy request.
 *
 * Kolai speaks WooCommerce: a line is productId [+ variationId] + quantity.
 * Magento needs more, so this is where the two meet:
 *  - configurable: productId is the parent, variationId the child; the child's
 *    values for the configurable attributes become super_attribute. A bare child
 *    id that is not visible on its own is resolved to its configurable parent,
 *    which is what /products returns as variations[].id.
 *  - bundle: the default selections, as the storefront would add them. Kolai
 *    cannot pick bundle options, so the bundle's defaults are the only choice.
 *
 * Everything here only reads the catalogue; nothing is written.
 */
class LineResolver
{
    private ProductRepositoryInterface $productRepository;

    private StoreManagerInterface $storeManager;

    private ConfigurableResource $configurableResource;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        StoreManagerInterface $storeManager,
        ConfigurableResource $configurableResource
    ) {
        $this->productRepository    = $productRepository;
        $this->storeManager         = $storeManager;
        $this->configurableResource = $configurableResource;
    }

    /**
     * @param array<int,array{productId:int,variationId:int,quantity:int}> $lines
     * @return array<int,array{product:Product,request:DataObject,productId:int,quantity:int}>
     * @throws KolaiException
     */
    public function resolve(array $lines): array
    {
        $resolved = [];
        foreach ($lines as $line) {
            $resolved[] = $this->resolveLine($line);
        }

        return $resolved;
    }

    /**
     * @param array{productId:int,variationId:int,quantity:int} $line
     * @return array{product:Product,request:DataObject,productId:int,quantity:int}
     * @throws KolaiException
     */
    private function resolveLine(array $line): array
    {
        $product  = $this->load($line['productId']);
        $quantity = $line['quantity'];
        $child    = null;

        if ($product->getTypeId() !== Configurable::TYPE_CODE && $line['variationId'] <= 0) {
            $parent = $this->configurableParent($product);
            if ($parent !== null) {
                $child   = $product;
                $product = $parent;
            }
        }

        if ($product->getTypeId() === Configurable::TYPE_CODE) {
            if ($child === null) {
                if ($line['variationId'] <= 0) {
                    throw Errors::invalidProductList('variationId is required for a product with variants');
                }
                $child = $this->load($line['variationId']);
            }
            // The configurable type skips unsalable children when it matches
            // super attributes, so an out-of-stock variation would otherwise
            // surface as "You need to choose options" instead of a stock error.
            if (!$child->isSalable()) {
                throw Errors::insufficientStock('Product is out of stock');
            }

            return [
                'product'   => $product,
                'request'   => $this->configurableRequest($product, $child, $quantity),
                'productId' => (int) $child->getId(),
                'quantity'  => $quantity,
            ];
        }

        if ($line['variationId'] > 0) {
            throw Errors::invalidProductList('variationId given for a product without variants');
        }

        $request = $product->getTypeId() === 'bundle'
            ? $this->bundleRequest($product, $quantity)
            : new DataObject(['qty' => $quantity]);

        return [
            'product'   => $product,
            'request'   => $request,
            'productId' => (int) $product->getId(),
            'quantity'  => $quantity,
        ];
    }

    /**
     * A disabled product, or one outside this website, does not exist as far as
     * this storefront is concerned.
     *
     * @throws KolaiException
     */
    private function load(int $productId): Product
    {
        $store = $this->storeManager->getStore();

        try {
            /** @var Product $product */
            $product = $this->productRepository->getById($productId, false, (int) $store->getId(), true);
        } catch (NoSuchEntityException $e) {
            throw Errors::productNotFound();
        }

        if ((int) $product->getStatus() !== Status::STATUS_ENABLED
            || !in_array((int) $store->getWebsiteId(), array_map('intval', (array) $product->getWebsiteIds()), true)
        ) {
            throw Errors::productNotFound();
        }

        return $product;
    }

    /**
     * Only a child that cannot be bought on its own is redirected: a simple that
     * is both visible and part of a configurable is sold standalone too.
     */
    private function configurableParent(Product $product): ?Product
    {
        if (!in_array($product->getTypeId(), [ProductType::TYPE_SIMPLE, ProductType::TYPE_VIRTUAL], true)
            || (int) $product->getVisibility() !== Visibility::VISIBILITY_NOT_VISIBLE
        ) {
            return null;
        }

        foreach ($this->configurableResource->getParentIdsByChild((int) $product->getId()) as $parentId) {
            try {
                return $this->load((int) $parentId);
            } catch (KolaiException $e) {
                continue;
            }
        }

        return null;
    }

    /**
     * @throws KolaiException
     */
    private function configurableRequest(Product $parent, Product $child, int $quantity): DataObject
    {
        /** @var Configurable $type */
        $type     = $parent->getTypeInstance();
        $childIds = [];
        foreach ($type->getChildrenIds((int) $parent->getId()) as $group) {
            $childIds = array_merge($childIds, array_map('intval', array_values((array) $group)));
        }
        if (!in_array((int) $child->getId(), $childIds, true)) {
            throw Errors::invalidProductList('variationId does not belong to productId');
        }

        $superAttribute = [];
        foreach ($type->getConfigurableAttributesAsArray($parent) as $attributeId => $attribute) {
            $value = $child->getData((string) $attribute['attribute_code']);
            if ($value === null || $value === '') {
                throw Errors::invalidProductList('Variation has no value for ' . $attribute['attribute_code']);
            }
            $superAttribute[(int) $attributeId] = (int) $value;
        }

        return new DataObject([
            'qty'             => $quantity,
            'product'         => (int) $parent->getId(),
            'selected_configurable_option' => (int) $child->getId(),
            'super_attribute' => $superAttribute,
        ]);
    }

    /**
     * The bundle's default selections, at their default quantities.
     *
     * @throws KolaiException
     */
    private function bundleRequest(Product $bundle, int $quantity): DataObject
    {
        $type       = $bundle->getTypeInstance();
        $options    = $type->getOptionsCollection($bundle);
        $selections = $type->getSelectionsCollection($type->getOptionsIds($bundle), $bundle);

        $defaults = [];
        foreach ($selections as $selection) {
            if ((int) $selection->getIsDefault() === 1) {
                $defaults[(int) $selection->getOptionId()][] = $selection;
            }
        }

        $bundleOption = [];
        $bundleQty    = [];
        foreach ($options as $option) {
            $optionId = (int) $option->getOptionId();
            $chosen   = $defaults[$optionId] ?? [];

            if ($chosen === []) {
                if ((int) $option->getRequired() === 1) {
                    throw Errors::invalidProductList(
                        'Bundle product could not be added: a required option has no default selection'
                    );
                }
                continue;
            }

            if (in_array($option->getType(), ['multi', 'checkbox'], true)) {
                $bundleOption[$optionId] = array_map(static function ($selection): int {
                    return (int) $selection->getSelectionId();
                }, $chosen);
                continue;
            }

            $bundleOption[$optionId] = (int) $chosen[0]->getSelectionId();
            $bundleQty[$optionId]    = max(1, (float) $chosen[0]->getSelectionQty());
        }

        return new DataObject([
            'qty'               => $quantity,
            'product'           => (int) $bundle->getId(),
            'bundle_option'     => $bundleOption,
            'bundle_option_qty' => $bundleQty,
        ]);
    }
}
