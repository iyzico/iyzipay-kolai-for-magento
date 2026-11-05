<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Endpoint;

use Iyzico\Kolai\Model\Api\ApiRequest;
use Iyzico\Kolai\Model\Api\Result;
use Iyzico\Kolai\Model\Product\CatalogSupport;
use Iyzico\Kolai\Model\Product\ProductReader;
use Kolai\Exception\Errors;
use Kolai\Exception\KolaiException;
use Kolai\Mapper\ProductMapper;

/**
 * GET /products, /products/{id}, /products-with-variants/{id}.
 */
class Products
{
    private ProductReader $reader;

    private CatalogSupport $catalog;

    public function __construct(ProductReader $reader, CatalogSupport $catalog)
    {
        $this->reader  = $reader;
        $this->catalog = $catalog;
    }

    /**
     * @throws KolaiException
     */
    public function list(ApiRequest $request): Result
    {
        $page    = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', ProductReader::DEFAULT_PER_PAGE);

        $found = $this->reader->summaries(
            $page,
            $perPage > 0 ? $perPage : ProductReader::DEFAULT_PER_PAGE,
            $this->ids($request->query('ids')),
            $this->modifiedAfter($request->query('modified_after'))
        );

        return Result::paginated(
            $this->mapper()->mapMany($found['items']),
            $found['page'],
            $found['perPage'],
            $found['total']
        );
    }

    /**
     * @throws KolaiException
     */
    public function get(ApiRequest $request): Result
    {
        return new Result($this->map($this->reader->detail($this->productId($request))));
    }

    /**
     * @throws KolaiException
     */
    public function getWithVariants(ApiRequest $request): Result
    {
        return new Result($this->map($this->reader->detailWithVariants($this->productId($request))));
    }

    /**
     * @param array<string,mixed> $product
     * @return array<string,mixed>|null
     */
    private function map(array $product): ?array
    {
        $bundled = $product['bundled_items'] ?? null;
        unset($product['bundled_items']);

        $mapped = $this->mapper()->map($product);
        // ProductMapper has no notion of bundle contents; they are already in
        // Kolai's item shape, so they ride along unchanged.
        if ($mapped !== null && is_array($bundled)) {
            $mapped['bundledItems'] = $bundled;
        }

        return $mapped;
    }

    private function mapper(): ProductMapper
    {
        return new ProductMapper($this->catalog->currency());
    }

    /**
     * @throws KolaiException
     */
    private function productId(ApiRequest $request): int
    {
        $id = $request->param('id');
        if (!ctype_digit($id) || (int) $id <= 0) {
            throw Errors::invalidProductId();
        }

        return (int) $id;
    }

    /**
     * Accepts "12,34" or ids[]=12&ids[]=34.
     *
     * @param mixed $raw
     * @return int[]
     */
    private function ids($raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }
        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);

        $ids = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if (ctype_digit($part) && (int) $part > 0) {
                $ids[(int) $part] = (int) $part;
            }
        }
        if ($ids === []) {
            throw Errors::invalidProductList('ids must be a comma separated list of product ids');
        }

        return array_values($ids);
    }

    /**
     * @param mixed $raw
     * @throws KolaiException
     */
    private function modifiedAfter($raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $timestamp = is_string($raw) ? strtotime($raw) : false;
        if ($timestamp === false) {
            throw Errors::badRequest('modified_after must be an ISO-8601 date');
        }

        return $timestamp;
    }
}
