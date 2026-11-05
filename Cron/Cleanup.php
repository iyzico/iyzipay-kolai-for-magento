<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Cron;

use Iyzico\Kolai\Model\Auth\NonceStore;
use Iyzico\Kolai\Model\Config;
use Iyzico\Kolai\Model\Log\LogRepository;

/**
 * Hourly: drops lapsed request salts and log rows past their retention.
 */
class Cleanup
{
    private NonceStore $nonceStore;

    private LogRepository $logRepository;

    private Config $config;

    public function __construct(NonceStore $nonceStore, LogRepository $logRepository, Config $config)
    {
        $this->nonceStore    = $nonceStore;
        $this->logRepository = $logRepository;
        $this->config        = $config;
    }

    public function execute(): void
    {
        $this->nonceStore->purgeExpired();
        $this->logRepository->deleteOlderThan($this->config->getLogRetentionDays());
    }
}
