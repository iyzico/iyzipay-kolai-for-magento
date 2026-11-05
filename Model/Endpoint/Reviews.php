<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Endpoint;

use Iyzico\Kolai\Model\Api\ApiRequest;
use Iyzico\Kolai\Model\Api\Result;
use Iyzico\Kolai\Model\Review\ReviewReader;
use Kolai\Exception\Errors;
use Kolai\Exception\KolaiException;
use Kolai\Mapper\ReviewMapper;

/**
 * GET /products/{id}/reviews and GET /reviews/{id}.
 */
class Reviews
{
    private ReviewReader $reader;

    public function __construct(ReviewReader $reader)
    {
        $this->reader = $reader;
    }

    /**
     * @throws KolaiException
     */
    public function list(ApiRequest $request): Result
    {
        $productId = $request->param('id');
        if (!ctype_digit($productId) || (int) $productId <= 0) {
            throw Errors::invalidProductId();
        }

        $perPage = (int) $request->query('per_page', ReviewReader::DEFAULT_PER_PAGE);

        $found = $this->reader->forProduct(
            (int) $productId,
            (int) $request->query('page', 1),
            $perPage > 0 ? $perPage : ReviewReader::DEFAULT_PER_PAGE,
            (string) $request->query('status', 'approved'),
            $this->rating($request->query('rating')),
            $this->since($request->query('modified_after'))
        );

        return Result::paginated(
            (new ReviewMapper())->mapMany($found['items']),
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
        $reviewId = $request->param('id');
        if (!ctype_digit($reviewId) || (int) $reviewId <= 0) {
            throw Errors::invalidReviewRequest('Invalid review id');
        }

        return new Result((new ReviewMapper())->map($this->reader->one((int) $reviewId)));
    }

    /**
     * @param mixed $raw
     * @throws KolaiException
     */
    private function rating($raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_numeric($raw) || (string) (int) $raw !== trim((string) $raw)) {
            throw Errors::invalidRating('rating must be between 1 and 5');
        }

        return (int) $raw;
    }

    /**
     * @param mixed $raw
     * @throws KolaiException
     */
    private function since($raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $timestamp = is_string($raw) ? strtotime($raw) : false;
        if ($timestamp === false) {
            throw Errors::invalidReviewRequest('modified_after must be an ISO-8601 date');
        }

        return $timestamp;
    }
}
