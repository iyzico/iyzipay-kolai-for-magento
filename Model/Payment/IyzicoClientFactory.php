<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Payment;

use Iyzico\Kolai\Model\Config;
use Iyzico\Kolai\Model\Log\Logger;
use Kolai\Payment\IyzicoClient;
use Kolai\Payment\IyzicoCredentials;
use Magento\Sales\Model\Order;

/**
 * iyzico keys are website-scoped, so the client is built per order from the
 * website the order was placed on — not from whatever scope the admin is in.
 */
class IyzicoClientFactory
{
    private Config $config;

    private Logger $logger;

    public function __construct(Config $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function forOrder(Order $order): IyzicoClient
    {
        $websiteId = (int) $order->getStore()->getWebsiteId();

        return new IyzicoClient(
            new IyzicoCredentials(
                $this->config->getIyzicoApiKey($websiteId),
                $this->config->getIyzicoSecretKey($websiteId),
                $this->config->getIyzicoEnvironment($websiteId)
            ),
            $this->logger
        );
    }
}
