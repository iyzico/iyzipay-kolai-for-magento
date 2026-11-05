<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Api;

use Iyzico\Kolai\Model\Auth\NonceStore;
use Iyzico\Kolai\Model\Config;
use Iyzico\Kolai\Model\Endpoint\Contracts;
use Iyzico\Kolai\Model\Endpoint\Orders;
use Iyzico\Kolai\Model\Endpoint\Products;
use Iyzico\Kolai\Model\Endpoint\Reviews;
use Iyzico\Kolai\Model\Endpoint\Shipping;
use Iyzico\Kolai\Model\Log\Logger;
use Kolai\Auth\RequestVerifier;
use Kolai\Auth\SignedRequest;
use Kolai\Exception\Errors;
use Kolai\Exception\KolaiException;
use Kolai\Log\Redactor;
use Kolai\Payment\RefundAllocator;
use Kolai\Protocol\Envelope;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Throwable;

/**
 * One Kolai API call, start to finish: route, enabled check, HMAC, decode,
 * endpoint, envelope. Returns [httpStatus, envelope]; the controller only
 * serialises it.
 *
 * Endpoints arrive as proxies (etc/di.xml), so a product read never builds the
 * order stack.
 */
class Dispatcher
{
    public const PATH_PREFIX = 'kolai/v1';

    private Routes $routes;

    private Config $config;

    private NonceStore $nonceStore;

    private Logger $logger;

    private ProductMetadataInterface $productMetadata;

    /** @var array<string,object> */
    private array $endpoints;

    public function __construct(
        Routes $routes,
        Config $config,
        NonceStore $nonceStore,
        Logger $logger,
        ProductMetadataInterface $productMetadata,
        Products $products,
        Reviews $reviews,
        Shipping $shipping,
        Orders $orders,
        Contracts $contracts
    ) {
        $this->routes          = $routes;
        $this->config          = $config;
        $this->nonceStore      = $nonceStore;
        $this->logger          = $logger;
        $this->productMetadata = $productMetadata;
        $this->endpoints       = [
            'products'  => $products,
            'reviews'   => $reviews,
            'shipping'  => $shipping,
            'orders'    => $orders,
            'contracts' => $contracts,
        ];
    }

    /**
     * @return array{0:int,1:array<string,mixed>}
     */
    public function dispatch(HttpRequest $request): array
    {
        $method   = strtoupper((string) $request->getMethod());
        $path     = $this->apiPath((string) $request->getPathInfo());
        $started  = microtime(true);
        $envelope = new Envelope(['magentoVersion' => $this->productMetadata->getVersion()]);

        $this->logger->beginRequest(RefundAllocator::newOperationId(), $method, '/' . self::PATH_PREFIX . '/' . $path);

        try {
            $route = $this->routes->match($method, $path);

            if (!$this->config->isEnabled()) {
                throw Errors::serviceUnavailable('Kolai integration is disabled');
            }

            // GET is signed over an empty body, whatever the client attached —
            // the Java signer does the same.
            $raw = $method === 'GET' ? '' : (string) $request->getContent();

            $this->verifier()->verify(
                SignedRequest::fromRequestUri(
                    $this->authorizationHeader($request),
                    (string) $request->getServer('REQUEST_URI'),
                    $raw
                ),
                $route['scope']
            );

            $body = $this->decode($raw);
            $this->logger->info('kolai.request: started', [
                'endpoint' => $route['endpoint'] . '.' . $route['action'],
                'params'   => $route['params'],
                'query'    => Redactor::payload($request->getQuery()->toArray()),
                'body'     => Redactor::payload($body),
            ]);

            $result = $this->endpoints[$route['endpoint']]->{$route['action']}(
                new ApiRequest($route['params'], $request->getQuery()->toArray(), $body)
            );

            $status  = 200;
            $payload = $envelope->success($result->data(), $result->meta());
        } catch (KolaiException $e) {
            $status  = $e->getHttpStatus();
            $payload = $envelope->fromException($e);
            $this->logger->warning('kolai.request: ' . $e->getMessage(), [
                'errorCode' => $e->getErrorCode(),
                'status'    => $status,
            ]);
        } catch (Throwable $e) {
            $status  = 500;
            $payload = $envelope->unexpectedError();
            $this->logger->error('kolai.request: unexpected error', ['exception' => $e]);
        }

        $this->logger->info('kolai.request: finished', [
            'status'      => $status,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return [$status, $payload];
    }

    private function verifier(): RequestVerifier
    {
        return new RequestVerifier(
            $this->config->getApiKey(),
            $this->config->getSecretKey(),
            $this->nonceStore,
            $this->logger
        );
    }

    /** "kolai/v1/products/12" -> "products/12". */
    private function apiPath(string $pathInfo): string
    {
        $path = trim($pathInfo, '/');

        return trim((string) substr($path, strlen(self::PATH_PREFIX)), '/');
    }

    private function authorizationHeader(HttpRequest $request): string
    {
        $header = $request->getHeader('Authorization');
        if (!is_string($header) || $header === '') {
            // Some FastCGI setups only pass it through after a rewrite.
            $header = (string) ($request->getServer('HTTP_AUTHORIZATION')
                ?: $request->getServer('REDIRECT_HTTP_AUTHORIZATION'));
        }

        return trim($header);
    }

    /**
     * @return array<string,mixed>
     * @throws KolaiException
     */
    private function decode(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->logger->warning('kolai.request: invalid JSON body', Redactor::unparsedBody($raw));
            throw Errors::badRequest('Invalid request body');
        }

        return $decoded;
    }
}
