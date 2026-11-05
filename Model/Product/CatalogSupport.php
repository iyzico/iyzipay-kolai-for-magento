<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Product;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\CatalogInventory\Model\ResourceModel\Stock\Status as StockStatus;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Small catalog helpers shared by the product readers: image URLs, optional
 * attributes, and stock data on collections.
 */
class CatalogSupport
{
    private StoreManagerInterface $storeManager;

    private EavConfig $eavConfig;

    private StockStatus $stockStatus;

    /** @var array<string,bool> */
    private array $attributeExists = [];

    public function __construct(StoreManagerInterface $storeManager, EavConfig $eavConfig, StockStatus $stockStatus)
    {
        $this->storeManager = $storeManager;
        $this->eavConfig    = $eavConfig;
        $this->stockStatus  = $stockStatus;
    }

    /**
     * The original upload, not a resized cache copy: Kolai renders its own sizes,
     * and the cache URL changes whenever the image config does.
     */
    public function imageUrl(?string $file): ?string
    {
        if ($file === null || $file === '' || $file === 'no_selection') {
            return null;
        }

        return rtrim($this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/')
            . '/catalog/product/' . ltrim($file, '/');
    }

    /** @return array{url:string}|null */
    public function image(Product $product): ?array
    {
        $url = $this->imageUrl((string) $product->getData('image'));

        return $url === null ? null : ['url' => $url];
    }

    /**
     * Selecting an attribute that does not exist throws, and length/width/height
     * or bundle's price_type exist only on some installs.
     *
     * @param string[] $codes
     * @return string[]
     */
    public function existingAttributes(array $codes): array
    {
        $existing = [];
        foreach ($codes as $code) {
            if (!isset($this->attributeExists[$code])) {
                $attribute                   = $this->eavConfig->getAttribute(Product::ENTITY, $code);
                $this->attributeExists[$code] = $attribute && $attribute->getId();
            }
            if ($this->attributeExists[$code]) {
                $existing[] = $code;
            }
        }

        return $existing;
    }

    /**
     * Joins stock status so isSalable() reflects stock, not just the enabled
     * flag. MSI adapts this join to the website's stock through a plugin.
     *
     * The flag stops the frontend beforeLoad plugin (AddStockStatusToCollection)
     * from joining the same alias again, which throws, and from dropping
     * out-of-stock products when "Display Out of Stock Products" is off; Kolai
     * must still see them, as out of stock.
     */
    public function addStock(Collection $collection): void
    {
        $from = $collection->getSelect()->getPart(\Magento\Framework\DB\Select::FROM);
        if (!isset($from['stock_status_index'])) {
            $this->stockStatus->addStockDataToCollection($collection, false);
        }
        $collection->setFlag('has_stock_status_filter', true);
    }

    /**
     * @return array{length?:string,width?:string,height?:string}
     */
    public function dimensions(Product $product): array
    {
        $dimensions = [];
        foreach (['length', 'width', 'height'] as $code) {
            $value = $product->getData($code);
            if ($value !== null && $value !== '' && (float) $value > 0) {
                $dimensions[$code] = (string) (float) $value;
            }
        }

        return $dimensions;
    }

    public function storeId(): int
    {
        return (int) $this->storeManager->getStore()->getId();
    }

    public function websiteId(): int
    {
        return (int) $this->storeManager->getStore()->getWebsiteId();
    }

    /** Prices are read in base currency, so that is the currency to report. */
    public function currency(): string
    {
        return (string) $this->storeManager->getStore()->getBaseCurrencyCode();
    }
}
