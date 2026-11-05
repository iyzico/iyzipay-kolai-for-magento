<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Api;

/**
 * What an endpoint gets: the already-verified request, decoded. Endpoints never
 * see the raw body or the Authorization header — by the time one runs, the HMAC
 * has been checked against exactly those bytes.
 */
class ApiRequest
{
    /** @var array<string,string> */
    private array $params;

    /** @var array<string,mixed> */
    private array $query;

    /** @var array<string,mixed> */
    private array $body;

    /**
     * @param array<string,string> $params Named groups captured from the route.
     * @param array<string,mixed>  $query
     * @param array<string,mixed>  $body   Decoded JSON; [] for GET or an empty body.
     */
    public function __construct(array $params, array $query, array $body)
    {
        $this->params = $params;
        $this->query  = $query;
        $this->body   = $body;
    }

    public function param(string $name): string
    {
        return (string) ($this->params[$name] ?? '');
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function query(string $name, $default = null)
    {
        return $this->query[$name] ?? $default;
    }

    /** @return array<string,mixed> */
    public function body(): array
    {
        return $this->body;
    }
}
