<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Db;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\ResourceConnection\ConnectionFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * A second database connection that never takes part in Magento's transaction.
 *
 * Needed for two kinds of write that must survive a rollback:
 *  - the refund ledger: Magento calls the payment method's refund() INSIDE the
 *    credit memo transaction, so a ledger row written on the default connection
 *    is erased when a later step throws — after iyzico already moved the money.
 *    The next retry would then refund the same transaction twice.
 *  - log rows, which matter most exactly when something rolls back.
 *
 * Opened lazily: requests that never write either pay nothing.
 */
class IsolatedConnection
{
    private DeploymentConfig $deploymentConfig;

    private ConnectionFactory $connectionFactory;

    private ResourceConnection $resource;

    private ?AdapterInterface $connection = null;

    public function __construct(
        DeploymentConfig $deploymentConfig,
        ConnectionFactory $connectionFactory,
        ResourceConnection $resource
    ) {
        $this->deploymentConfig  = $deploymentConfig;
        $this->connectionFactory = $connectionFactory;
        $this->resource          = $resource;
    }

    public function get(): AdapterInterface
    {
        if ($this->connection === null) {
            $config = (array) $this->deploymentConfig->get('db/connection/default', []);
            $this->connection = $this->connectionFactory->create($config);
        }

        return $this->connection;
    }

    /** Prefixed table name; identical on both connections. */
    public function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }
}
