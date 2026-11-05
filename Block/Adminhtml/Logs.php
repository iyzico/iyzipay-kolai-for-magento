<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Block\Adminhtml;

use Iyzico\Kolai\Model\Config;
use Iyzico\Kolai\Model\Log\Logger;
use Iyzico\Kolai\Model\Log\LogRepository;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/**
 * Log table with level / context / free-text / request id filters. The filters
 * are GET parameters, so the request-id link on a row is just another filter.
 */
class Logs extends Template
{
    protected $_template = 'Iyzico_Kolai::logs.phtml';

    private const FILTERS = ['level', 'context', 'search', 'request_id'];

    private LogRepository $logs;

    private Config $config;

    /** @var array{rows:array<int,array<string,mixed>>,total:int,page:int,pageSize:int}|null */
    private ?array $result = null;

    public function __construct(Context $context, LogRepository $logs, Config $config, array $data = [])
    {
        parent::__construct($context, $data);
        $this->logs   = $logs;
        $this->config = $config;
    }

    /** @return array<string,string> */
    public function getFilters(): array
    {
        $filters = [];
        foreach (self::FILTERS as $name) {
            $filters[$name] = trim((string) $this->getRequest()->getParam($name, ''));
        }

        return $filters;
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pageSize:int} */
    public function getResult(): array
    {
        if ($this->result === null) {
            $this->result = $this->logs->search(
                array_filter($this->getFilters(), 'strlen'),
                (int) $this->getRequest()->getParam('p', 1)
            );
        }

        return $this->result;
    }

    public function getTotalPages(): int
    {
        $result = $this->getResult();

        return max(1, (int) ceil($result['total'] / max(1, $result['pageSize'])));
    }

    /** @return string[] */
    public function getLevels(): array
    {
        return array_keys(Logger::LEVELS);
    }

    /** @return string[] */
    public function getContexts(): array
    {
        return $this->logs->contexts();
    }

    public function isLogEnabled(): bool
    {
        return $this->config->isLogEnabled();
    }

    public function getMinimumLevel(): string
    {
        return $this->config->getLogLevel();
    }

    /**
     * The current filters with $overrides applied; '' drops a filter.
     *
     * @param array<string,string|int> $overrides
     */
    public function getFilterUrl(array $overrides = []): string
    {
        $query = array_filter(array_merge($this->getFilters(), $overrides), static function ($v): bool {
            return (string) $v !== '';
        });

        return $this->getUrl('kolai/logs/index', ['_query' => $query]);
    }

    public function getIndexUrl(): string
    {
        return $this->getUrl('kolai/logs/index');
    }

    public function getClearUrl(): string
    {
        return $this->getUrl('kolai/logs/clear');
    }

    public function getSettingsUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'kolai']);
    }

    /** Pretty JSON for the expandable data cell; '' when there is none. */
    public function formatData(?string $data): string
    {
        if ($data === null || $data === '') {
            return '';
        }
        $decoded = json_decode($data, true);

        return $decoded === null
            ? $data
            : (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
