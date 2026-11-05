<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Product;

use Iyzico\Kolai\Model\Log\Logger;
use Kolai\Exception\Errors;
use Kolai\Exception\KolaiException;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Review\Model\ReviewFactory;

/**
 * Reads catalog products into the neutral snake_case arrays that
 * Kolai\Mapper\ProductMapper consumes. Two shapes:
 *
 *  - summary (GET /products): cheap fields only, built from one collection
 *    query with URL rewrites, stock and review summaries joined in bulk.
 *  - detail (GET /products/{id}): adds description, gallery, attributes,
 *    variations and bundle contents.
 *
 * Only products a shopper could reach are exposed: enabled, in the current
 * website, visible in catalog or search.
 */
class ProductReader
{
    public const DEFAULT_PER_PAGE = 100;
    public const MAX_PER_PAGE     = 200;
    public const MAX_IDS          = 200;

    /** Magento type ids reported under WooCommerce's names, which Kolai was built against. */
    private const TYPE_NAMES = ['configurable' => 'variable'];

    private const SUMMARY_ATTRIBUTES = [
        'name', 'price', 'special_price', 'special_from_date', 'special_to_date', 'tax_class_id',
        'image', 'url_key', 'weight', 'status', 'visibility', 'price_type', 'length', 'width', 'height',
    ];

    private CollectionFactory $collectionFactory;

    private ProductRepositoryInterface $productRepository;

    private Visibility $visibility;

    private ReviewFactory $reviewFactory;

    private ConfigurableResource $configurableResource;

    private PriceResolver $prices;

    private VariationReader $variations;

    private BundleReader $bundles;

    private CatalogSupport $catalog;

    private Logger $logger;

    public function __construct(
        CollectionFactory $collectionFactory,
        ProductRepositoryInterface $productRepository,
        Visibility $visibility,
        ReviewFactory $reviewFactory,
        ConfigurableResource $configurableResource,
        PriceResolver $prices,
        VariationReader $variations,
        BundleReader $bundles,
        CatalogSupport $catalog,
        Logger $logger
    ) {
        $this->collectionFactory    = $collectionFactory;
        $this->productRepository    = $productRepository;
        $this->visibility           = $visibility;
        $this->reviewFactory        = $reviewFactory;
        $this->configurableResource = $configurableResource;
        $this->prices               = $prices;
        $this->variations           = $variations;
        $this->bundles              = $bundles;
        $this->catalog              = $catalog;
        $this->logger               = $logger;
    }

    /**
     * @param int[] $ids Non-empty: fetch exactly these (capped), pagination off.
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,perPage:int}
     */
    public function summaries(int $page, int $perPage, array $ids, ?int $modifiedAfter): array
    {
        $started = microtime(true);
        $page    = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        $collection = $this->collectionFactory->create();
        $collection->addStoreFilter($this->catalog->storeId())
            ->addAttributeToSelect($this->catalog->existingAttributes(self::SUMMARY_ATTRIBUTES))
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', ['in' => $this->visibility->getVisibleInSiteIds()])
            ->setOrder('entity_id', 'ASC');

        if ($ids !== []) {
            if (count($ids) > self::MAX_IDS) {
                $this->logger->warning('kolai.product: ids list capped', [
                    'requested' => count($ids),
                    'cap'       => self::MAX_IDS,
                ]);
                $ids = array_slice($ids, 0, self::MAX_IDS);
            }
            $collection->addIdFilter($ids);
            $page    = 1;
            $perPage = max(1, count($ids));
        }
        if ($modifiedAfter !== null) {
            $collection->addAttributeToFilter('updated_at', ['gteq' => gmdate('Y-m-d H:i:s', $modifiedAfter)]);
        }

        $total = (int) $collection->getSize();

        // Magento clamps an out-of-range page to the last one; Kolai paging past
        // the end must get an empty page, not the last page again.
        if ($total === 0 || ($page - 1) * $perPage >= $total) {
            return ['items' => [], 'total' => $total, 'page' => $page, 'perPage' => $perPage];
        }

        $collection->setPageSize($perPage)->setCurPage($page);
        $collection->addUrlRewrite();
        $this->catalog->addStock($collection);
        $collection->load();
        $this->reviewFactory->create()->appendSummary($collection);

        $queryMs = (int) round((microtime(true) - $started) * 1000);

        $items = [];
        /** @var Product $product */
        foreach ($collection as $product) {
            try {
                $items[] = $this->summary($product);
            } catch (\Throwable $e) {
                // One broken product must not take the whole page down.
                $this->logger->error('kolai.product: format failed', [
                    'product_id' => (int) $product->getId(),
                    'exception'  => $e,
                ]);
            }
        }

        $this->logger->info('kolai.product: list query finished', [
            'page'        => $page,
            'per_page'    => $perPage,
            'count'       => count($items),
            'total'       => $total,
            'query_ms'    => $queryMs,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return ['items' => $items, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }

    /**
     * @return array<string,mixed> Neutral detail array; bundles carry
     *         `bundled_items` (already in Kolai's camelCase item shape).
     * @throws KolaiException
     */
    public function detail(int $productId): array
    {
        $started = microtime(true);
        $product = $this->loadVisible($productId);
        $data    = $this->format($product);

        $this->logger->info('kolai.product: single product fetched', [
            'product_id'  => $productId,
            'type'        => $product->getTypeId(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return $data;
    }

    /**
     * A configurable child resolves to its parent, with every variation.
     *
     * @return array<string,mixed>
     * @throws KolaiException
     */
    public function detailWithVariants(int $productId): array
    {
        $started  = microtime(true);
        $product  = $this->load($productId);
        $parentIds = $product->getTypeId() === 'configurable'
            ? []
            : array_map('intval', $this->configurableResource->getParentIdsByChild($productId));

        if ($parentIds === []) {
            $this->assertVisible($product);

            return $this->format($product);
        }

        // A child is usually "not visible individually" by design, so only its
        // enabled flag is checked; visibility is the parent's business.
        if ((int) $product->getStatus() !== Status::STATUS_ENABLED) {
            throw Errors::productNotVisible();
        }

        $parent = null;
        foreach ($parentIds as $parentId) {
            try {
                $parent = $this->load($parentId);
                break;
            } catch (KolaiException $e) {
                continue;
            }
        }
        if ($parent === null) {
            $this->logger->warning('kolai.product: variation parent not found', [
                'variation_id' => $productId,
                'parent_ids'   => $parentIds,
            ]);
            throw Errors::variationParentNotFound();
        }
        if (!$this->isVisible($parent)) {
            throw Errors::productNotVisible('Variation parent product not visible');
        }

        $data = $this->format($parent);

        $this->logger->info('kolai.product: variation resolved to parent', [
            'variation_id' => $productId,
            'parent_id'    => (int) $parent->getId(),
            'duration_ms'  => (int) round((microtime(true) - $started) * 1000),
        ]);

        return $data;
    }

    /**
     * @return array<string,mixed>
     */
    private function summary(Product $product): array
    {
        // A dynamic bundle's price info is 0; the list must show the same
        // default-selection total the detail and POST /orders use.
        if ($product->getTypeId() === 'bundle') {
            $bundlePrice = $this->bundles->read($product)['price'];
            if ($bundlePrice !== null) {
                return $this->base($product) + $bundlePrice + $this->rating($product->getData('rating_summary'));
            }
        }

        $raw  = $this->prices->raw($product);
        $data = $this->base($product)
            + $this->prices->fields($product, $raw['regular'], $raw['final'])
            + $this->prices->saleWindow($product, $raw['final'])
            + $this->rating($product->getData('rating_summary'));

        return $data;
    }

    /**
     * @return array<string,mixed>
     */
    private function format(Product $product): array
    {
        $this->reviewFactory->create()->getEntitySummary($product, $this->catalog->storeId());

        $data = $this->base($product) + $this->rating($product->getData('rating_summary'));
        $data['description'] = (string) $product->getData('description');
        $data['gallery']     = $this->gallery($product);

        $attributes = [];
        $priced     = false;

        if ($product->getTypeId() === 'configurable') {
            $configurable = $this->variations->configurableAttributes($product);
            $attributes   = $this->variations->taxonomyAttributes($configurable);
            $variations   = $this->variations->variations($product, $configurable);
            $data['variations'] = $variations['variations'];

            if ($variations['truncated']) {
                $this->logger->warning('kolai.product: variations truncated', [
                    'parent_id' => (int) $product->getId(),
                    'total'     => $variations['total'],
                    'returned'  => VariationReader::MAX_VARIATIONS,
                ]);
            }

            $cheapest = $this->variations->cheapest($variations['children']);
            if ($cheapest !== null) {
                $raw  = $this->prices->raw($cheapest);
                $data += $this->prices->fields($cheapest, $raw['regular'], $raw['final'])
                    + $this->prices->saleWindow($cheapest, $raw['final']);
                $priced = true;
            }
        } else {
            $data['variations'] = [];
        }

        if ($product->getTypeId() === 'bundle') {
            $bundle                = $this->bundles->read($product);
            $data['bundled_items'] = $bundle['items'];
            if ($bundle['price'] !== null) {
                $data  += $bundle['price'];
                $priced = true;
            }
        }

        if (!$priced) {
            $raw  = $this->prices->raw($product);
            $data += $this->prices->fields($product, $raw['regular'], $raw['final'])
                + $this->prices->saleWindow($product, $raw['final']);
        }

        $data['attributes'] = array_merge(
            $attributes,
            $this->customAttributes($product, array_column($attributes, 'slug'))
        );

        return $data;
    }

    /**
     * Fields both shapes share.
     *
     * @return array<string,mixed>
     */
    private function base(Product $product): array
    {
        $data = [
            'id'        => (int) $product->getId(),
            'name'      => (string) $product->getName(),
            'permalink' => (string) $product->getProductUrl(),
            'in_stock'  => (bool) $product->isSalable(),
            'type'      => self::TYPE_NAMES[$product->getTypeId()] ?? (string) $product->getTypeId(),
            'sku'       => (string) $product->getSku(),
        ];

        $image = $this->catalog->image($product);
        if ($image !== null) {
            $data['image'] = $image;
        }
        if ((float) $product->getData('weight') > 0) {
            $data['weight'] = (string) (float) $product->getData('weight');
        }
        $dimensions = $this->catalog->dimensions($product);
        if ($dimensions !== []) {
            $data['dimensions'] = $dimensions;
        }

        return $data;
    }

    /**
     * @param mixed $summary DataObject set by appendSummary/getEntitySummary.
     * @return array{average_rating:float,review_count:int}
     */
    private function rating($summary): array
    {
        $percent = $summary instanceof DataObject ? (float) $summary->getData('rating_summary') : 0.0;
        $count   = $summary instanceof DataObject ? (int) $summary->getData('reviews_count') : 0;

        return ['average_rating' => round($percent / 20, 2), 'review_count' => $count];
    }

    /**
     * @return array<int,array{url:string}>
     */
    private function gallery(Product $product): array
    {
        $main    = (string) $product->getData('image');
        $gallery = [];
        $images  = $product->getMediaGalleryImages();
        if ($images === null) {
            return [];
        }
        foreach ($images as $image) {
            if ((int) $image->getData('disabled') || (string) $image->getData('file') === $main) {
                continue;
            }
            $url = (string) $image->getData('url');
            if ($url !== '') {
                $gallery[] = ['url' => $url];
            }
        }

        return $gallery;
    }

    /**
     * Storefront-visible attributes other than the configurable ones.
     *
     * @param string[] $skip
     * @return array<int,array<string,mixed>>
     */
    private function customAttributes(Product $product, array $skip): array
    {
        $out = [];
        foreach ($product->getAttributes() as $attribute) {
            $code = (string) $attribute->getAttributeCode();
            if (!$attribute->getIsVisibleOnFront() || in_array($code, $skip, true)) {
                continue;
            }
            if ($product->getData($code) === null || $product->getData($code) === '') {
                continue;
            }

            $value = $attribute->getFrontend()->getValue($product);
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $out[] = [
                'name'    => (string) ($attribute->getStoreLabel() ?: $attribute->getFrontendLabel()),
                'slug'    => $code,
                'type'    => 'custom',
                'visible' => true,
                'options' => [['name' => $value]],
            ];
        }

        return $out;
    }

    /**
     * @throws KolaiException
     */
    private function loadVisible(int $productId): Product
    {
        $product = $this->load($productId);
        $this->assertVisible($product);

        return $product;
    }

    /**
     * Missing and "exists but not in this website" are both not-found: the
     * other website's catalogue is none of this caller's business.
     *
     * @throws KolaiException
     */
    private function load(int $productId): Product
    {
        try {
            /** @var Product $product */
            $product = $this->productRepository->getById($productId, false, $this->catalog->storeId());
        } catch (NoSuchEntityException $e) {
            throw Errors::productNotFound();
        }

        if (!in_array($this->catalog->websiteId(), array_map('intval', (array) $product->getWebsiteIds()), true)) {
            throw Errors::productNotFound();
        }

        return $product;
    }

    /**
     * @throws KolaiException
     */
    private function assertVisible(Product $product): void
    {
        if (!$this->isVisible($product)) {
            $this->logger->warning('kolai.product: product not visible', [
                'product_id' => (int) $product->getId(),
                'status'     => (int) $product->getStatus(),
                'visibility' => (int) $product->getVisibility(),
            ]);
            throw Errors::productNotVisible();
        }
    }

    private function isVisible(Product $product): bool
    {
        return (int) $product->getStatus() === Status::STATUS_ENABLED
            && in_array((int) $product->getVisibility(), $this->visibility->getVisibleInSiteIds(), true);
    }
}
