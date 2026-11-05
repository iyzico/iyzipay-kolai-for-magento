<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Api;

/**
 * An endpoint's successful outcome. `meta` becomes extra top-level envelope
 * fields next to `data` — `pagination` on list endpoints, nothing elsewhere.
 */
class Result
{
    /** @var mixed */
    private $data;

    /** @var array<string,mixed> */
    private array $meta;

    /**
     * @param mixed               $data
     * @param array<string,mixed> $meta
     */
    public function __construct($data, array $meta = [])
    {
        $this->data = $data;
        $this->meta = $meta;
    }

    /**
     * @param array<int,mixed> $items
     */
    public static function paginated(array $items, int $page, int $perPage, int $total): self
    {
        return new self($items, [
            'pagination' => [
                'page'       => $page,
                'perPage'    => $perPage,
                'total'      => $total,
                'totalPages' => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
            ],
        ]);
    }

    /** @return mixed */
    public function data()
    {
        return $this->data;
    }

    /** @return array<string,mixed> */
    public function meta(): array
    {
        return $this->meta;
    }
}
