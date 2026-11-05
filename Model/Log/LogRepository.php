<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Log;

use Magento\Framework\App\ResourceConnection;

/**
 * Read side of kolai_log for the admin log page, plus retention cleanup.
 */
class LogRepository
{
    public const PAGE_SIZE = 100;

    private ResourceConnection $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * @param array{level?:string,context?:string,search?:string,request_id?:string} $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pageSize:int}
     */
    public function search(array $filters, int $page): array
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('kolai_log');
        $page       = max(1, $page);

        $select = $connection->select()->from($table);
        if (!empty($filters['level']) && isset(Logger::LEVELS[$filters['level']])) {
            $select->where('level = ?', $filters['level']);
        }
        if (!empty($filters['context'])) {
            $select->where('context = ?', (string) $filters['context']);
        }
        if (!empty($filters['request_id'])) {
            $select->where('request_id = ?', (string) $filters['request_id']);
        }
        if (!empty($filters['search'])) {
            $like = '%' . addcslashes((string) $filters['search'], '%_\\') . '%';
            $select->where('(message LIKE ? OR data LIKE ? OR route LIKE ?)', $like);
        }

        $countSelect = clone $select;
        $countSelect->reset(\Magento\Framework\DB\Select::COLUMNS)->columns(['cnt' => new \Zend_Db_Expr('COUNT(*)')]);
        $total = (int) $connection->fetchOne($countSelect);

        $select->order('log_id DESC')->limitPage($page, self::PAGE_SIZE);

        return [
            'rows'     => $connection->fetchAll($select),
            'total'    => $total,
            'page'     => $page,
            'pageSize' => self::PAGE_SIZE,
        ];
    }

    /** @return string[] Contexts present in the table, for the filter dropdown. */
    public function contexts(): array
    {
        $connection = $this->resource->getConnection();
        $select     = $connection->select()
            ->distinct()
            ->from($this->resource->getTableName('kolai_log'), ['context'])
            ->order('context ASC');

        return array_map('strval', $connection->fetchCol($select));
    }

    public function clear(): void
    {
        $this->resource->getConnection()->truncateTable($this->resource->getTableName('kolai_log'));
    }

    /** @return int Rows deleted. */
    public function deleteOlderThan(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        return (int) $this->resource->getConnection()->delete(
            $this->resource->getTableName('kolai_log'),
            ['created_at < ?' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]
        );
    }
}
