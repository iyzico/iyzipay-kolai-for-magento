<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Controller\Api;

use Iyzico\Kolai\Model\Api\Dispatcher;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPatchActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;

class Index implements
    HttpGetActionInterface,
    HttpPostActionInterface,
    HttpPatchActionInterface,
    CsrfAwareActionInterface
{
    private HttpRequest $request;

    private RawFactory $rawFactory;

    private Dispatcher $dispatcher;

    public function __construct(HttpRequest $request, RawFactory $rawFactory, Dispatcher $dispatcher)
    {
        $this->request    = $request;
        $this->rawFactory = $rawFactory;
        $this->dispatcher = $dispatcher;
    }

    public function execute(): ResultInterface
    {
        [$status, $payload] = $this->dispatcher->dispatch($this->request);

        /** @var Raw $result */
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode($status);
        $result->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        // Responses are per-caller and signed-request-bound: never let FPC or
        // Varnish store one.
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $result->setHeader('Pragma', 'no-cache', true);
        $result->setContents((string) json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        ));

        return $result;
    }

    /** The HMAC signature is the request's proof of origin; a form key cannot exist here. */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
