<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Controller;

use Iyzico\Kolai\Controller\Api\Index;
use Iyzico\Kolai\Model\Api\Dispatcher;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;

/**
 * Sends every /kolai/v1/* request to the single API controller.
 *
 * A router rather than webapi.xml: Kolai needs PATCH, its own envelope and
 * status codes, and the raw body bytes for the HMAC — Magento's REST layer
 * offers none of the three.
 */
class Router implements RouterInterface
{
    private ActionFactory $actionFactory;

    public function __construct(ActionFactory $actionFactory)
    {
        $this->actionFactory = $actionFactory;
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        $path = trim((string) $request->getPathInfo(), '/');
        if ($path !== Dispatcher::PATH_PREFIX && strpos($path, Dispatcher::PATH_PREFIX . '/') !== 0) {
            return null;
        }

        $request->setModuleName('kolai')
            ->setControllerName('api')
            ->setActionName('index');

        return $this->actionFactory->create(Index::class);
    }
}
