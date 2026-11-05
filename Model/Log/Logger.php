<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Log;

use Iyzico\Kolai\Model\Config;
use Iyzico\Kolai\Model\Db\IsolatedConnection;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

/**
 * PSR-3 logger writing to the kolai_log table, shown under System > Kolai Logs.
 *
 * Off unless the admin enables it; a disabled logger is a config read and
 * nothing else. Rows go through the isolated connection so a rolled-back credit
 * memo still leaves its refund trail behind.
 *
 * The context column comes from a "kolai.<context>:" message prefix — the form
 * kolai-php uses — or from a `_context` key; anything else lands in "general".
 * Never pass raw request bodies: run them through Kolai\Log\Redactor first.
 */
class Logger extends AbstractLogger
{
    public const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    /** PSR levels folded onto the four the admin can choose from. */
    private const PSR_TO_LEVEL = [
        LogLevel::DEBUG     => 'debug',
        LogLevel::INFO      => 'info',
        LogLevel::NOTICE    => 'info',
        LogLevel::WARNING   => 'warning',
        LogLevel::ERROR     => 'error',
        LogLevel::CRITICAL  => 'error',
        LogLevel::ALERT     => 'error',
        LogLevel::EMERGENCY => 'error',
    ];

    private const MAX_DATA_BYTES = 16000;

    private Config $config;

    private IsolatedConnection $db;

    private ?string $requestId = null;

    private ?string $method = null;

    private ?string $route = null;

    public function __construct(Config $config, IsolatedConnection $db)
    {
        $this->config = $config;
        $this->db     = $db;
    }

    /** Tags every following row with the API request it belongs to. */
    public function beginRequest(string $requestId, string $method, string $route): void
    {
        $this->requestId = $requestId;
        $this->method    = $method;
        $this->route     = $route;
    }

    /**
     * @param mixed                $level
     * @param string|Stringable    $message
     * @param array<string,mixed>  $context
     */
    public function log($level, $message, array $context = []): void
    {
        $level = self::PSR_TO_LEVEL[(string) $level] ?? 'info';
        if (!$this->isEnabledFor($level)) {
            return;
        }

        $message = (string) $message;
        $channel = 'general';
        if (isset($context['_context']) && is_string($context['_context'])) {
            $channel = $context['_context'];
            unset($context['_context']);
        } elseif (preg_match('/^kolai\.([a-z_]+):\s*(.*)$/s', $message, $m)) {
            $channel = $m[1];
            $message = $m[2];
        }

        $duration = null;
        if (isset($context['duration_ms']) && is_numeric($context['duration_ms'])) {
            $duration = (int) $context['duration_ms'];
            unset($context['duration_ms']);
        }

        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            $context['exception'] = self::describe($context['exception']);
        }

        $data = $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (is_string($data) && strlen($data) > self::MAX_DATA_BYTES) {
            $data = substr($data, 0, self::MAX_DATA_BYTES) . '… (truncated)';
        }

        try {
            $this->db->get()->insert($this->db->table('kolai_log'), [
                'level'       => $level,
                'context'     => substr($channel, 0, 32),
                'request_id'  => $this->requestId,
                'method'      => $this->method,
                'route'       => $this->route !== null ? substr($this->route, 0, 255) : null,
                'message'     => mb_substr($message, 0, 1000),
                'data'        => $data ?: null,
                'duration_ms' => $duration,
            ]);
        } catch (Throwable $e) {
            // Logging must never break the request it is describing.
        }
    }

    public function isEnabledFor(string $level): bool
    {
        if (!$this->config->isLogEnabled()) {
            return false;
        }
        $minimum = self::LEVELS[$this->config->getLogLevel()] ?? self::LEVELS['info'];

        return (self::LEVELS[$level] ?? 0) >= $minimum;
    }

    /**
     * Class, message and the first frames — enough to find the line, without
     * dumping arguments (which can carry customer data) into the log.
     *
     * @return array<string,mixed>
     */
    public static function describe(Throwable $e): array
    {
        $frames = [];
        foreach (array_slice($e->getTrace(), 0, 8) as $frame) {
            $frames[] = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '')
                . (isset($frame['file']) ? ' @ ' . basename($frame['file']) . ':' . ($frame['line'] ?? 0) : '');
        }

        return [
            'class'   => get_class($e),
            'message' => $e->getMessage(),
            'at'      => basename($e->getFile()) . ':' . $e->getLine(),
            'trace'   => $frames,
        ];
    }
}
