<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Api;

use Kolai\Auth\Scope;
use Kolai\Exception\Errors;
use Kolai\Exception\KolaiException;

/**
 * The /kolai/v1 route table: method + path pattern -> scope + handler.
 *
 * Each route demands exactly one scope, so a signature minted to read products
 * cannot create an order. Patterns are matched against the path AFTER
 * "kolai/v1/", without leading or trailing slash.
 */
class Routes
{
    /** @var array<int,array{0:string,1:string,2:string,3:string,4:string}> method, pattern, scope, endpoint, action */
    private const TABLE = [
        ['GET', '#^products$#', Scope::RETRIEVE_PRODUCTS, 'products', 'list'],
        ['GET', '#^products/(?<id>[^/]+)$#', Scope::RETRIEVE_PRODUCT, 'products', 'get'],
        ['GET', '#^products-with-variants/(?<id>[^/]+)$#', Scope::RETRIEVE_PRODUCT_WITH_VARIANTS, 'products', 'getWithVariants'],
        ['GET', '#^products/(?<id>[^/]+)/reviews$#', Scope::RETRIEVE_REVIEWS, 'reviews', 'list'],
        ['GET', '#^reviews/(?<id>[^/]+)$#', Scope::RETRIEVE_REVIEW, 'reviews', 'get'],
        ['POST', '#^(?:shipment|shipping)-options$#', Scope::RETRIEVE_SHIPMENT_OPTIONS, 'shipping', 'options'],
        ['GET', '#^order-types$#', Scope::RETRIEVE_ORDER_TYPES, 'orders', 'types'],
        ['POST', '#^orders$#', Scope::CREATE_ORDER, 'orders', 'create'],
        ['GET', '#^orders/(?<id>[^/]+)$#', Scope::RETRIEVE_ORDER, 'orders', 'get'],
        ['PATCH', '#^orders/(?<id>[^/]+)$#', Scope::UPDATE_ORDER_STATUS, 'orders', 'update'],
        ['POST', '#^contracts$#', Scope::RETRIEVE_CONTRACT, 'contracts', 'all'],
        ['GET', '#^contracts/clarification-text$#', Scope::RETRIEVE_CONTRACT, 'contracts', 'clarificationText'],
    ];

    /**
     * @return array{scope:string,endpoint:string,action:string,params:array<string,string>}
     * @throws KolaiException
     */
    public function match(string $method, string $path): array
    {
        $method = strtoupper($method);
        $path   = trim($path, '/');

        foreach (self::TABLE as [$routeMethod, $pattern, $scope, $endpoint, $action]) {
            if ($routeMethod !== $method || !preg_match($pattern, $path, $matches)) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = rawurldecode($value);
                }
            }

            return ['scope' => $scope, 'endpoint' => $endpoint, 'action' => $action, 'params' => $params];
        }

        throw Errors::notFound('No route was found matching the URL and request method');
    }
}
