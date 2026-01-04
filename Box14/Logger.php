<?php

declare(strict_types=1);

/**
 * Logger
 *
 * Simple, maintainable logger with multiple output handlers and level filtering.
 * Designed for production use with minimal overhead.
 */
class Logger
{
    // Log levels (in order of severity)
    public const DEBUG = 100;
    public const INFO = 200;
    public const WARNING = 300;
    public const ERROR = 400;

    private const LEVEL_NAMES = [
        self::DEBUG => "DEBUG",
        self::INFO => "INFO",
        self::WARNING => "WARNING",
        self::ERROR => "ERROR",
    ];

    /** @var int Current minimum log level */
    private int $minLevel;

    /** @var LogHandler Log output handler */
    private LogHandler $handler;

    /**
     * Create a new logger instance
     *
     * @param string $output Output type: 'file', 'syslog', or 'null'
     * @param string $minLevel Minimum level to log: 'debug', 'info', 'warning', 'error'
     * @param array $config Handler-specific configuration
     */
    public function __construct(
        string $output = "file",
        string $minLevel = "info",
        array $config = [],
    ) {
        $this->minLevel = $this->levelNameToInt($minLevel);
        $this->handler = $this->createHandler($output, $config);
    }

    /**
     * Log a message with context
     *
     * @param array $input Input with 'level', 'message', and optional 'context'
     * @return array Result with 'logged', 'timestamp', 'formatted'
     */
    public function log(array $input): array
    {
        $level = $input["level"] ?? "info";
        $message = $input["message"] ?? "";
        $context = $input["context"] ?? [];

        $levelInt = $this->levelNameToInt($level);

        // Filter by minimum level
        if ($levelInt < $this->minLevel) {
            return [
                "logged" => false,
                "timestamp" => time(),
                "formatted" => "",
            ];
        }

        $timestamp = time();
        $formatted = $this->format($levelInt, $message, $context, $timestamp);

        $this->handler->write($formatted);

        return [
            "logged" => true,
            "timestamp" => $timestamp,
            "formatted" => $formatted,
        ];
    }

    /**
     * Log multiple messages in batch
     *
     * @param array $inputs Array of log inputs
     * @return array Array of results
     */
    public function logBatch(array $inputs): array
    {
        $results = [];
        foreach ($inputs as $index => $input) {
            $results[$index] = $this->log($input);
        }
        return $results;
    }

    /**
     * Convenience method: Log debug message
     *
     * @param string $message Log message
     * @param array $context Optional context
     * @return array Result
     */
    public function debug(string $message, array $context = []): array
    {
        return $this->log([
            "level" => "debug",
            "message" => $message,
            "context" => $context,
        ]);
    }

    /**
     * Convenience method: Log info message
     *
     * @param string $message Log message
     * @param array $context Optional context
     * @return array Result
     */
    public function info(string $message, array $context = []): array
    {
        return $this->log([
            "level" => "info",
            "message" => $message,
            "context" => $context,
        ]);
    }

    /**
     * Convenience method: Log warning message
     *
     * @param string $message Log message
     * @param array $context Optional context
     * @return array Result
     */
    public function warning(string $message, array $context = []): array
    {
        return $this->log([
            "level" => "warning",
            "message" => $message,
            "context" => $context,
        ]);
    }

    /**
     * Convenience method: Log error message
     *
     * @param string $message Log message
     * @param array $context Optional context
     * @return array Result
     */
    public function error(string $message, array $context = []): array
    {
        return $this->log([
            "level" => "error",
            "message" => $message,
            "context" => $context,
        ]);
    }

    /**
     * Format a log message
     *
     * Format: [YYYY-MM-DD HH:MM:SS] LEVEL: message | key: value
     *
     * @param int $level Log level integer
     * @param string $message Log message
     * @param array $context Context data
     * @param int $timestamp Unix timestamp
     * @return string Formatted log line
     */
    private function format(
        int $level,
        string $message,
        array $context,
        int $timestamp,
    ): string {
        $date = date("Y-m-d H:i:s", $timestamp);
        $levelName = self::LEVEL_NAMES[$level];

        $parts = ["[{$date}]", "{$levelName}:", $message];

        // Append context as key: value pairs
        if (!empty($context)) {
            $contextParts = [];
            foreach ($context as $key => $value) {
                $contextParts[] =
                    "{$key}: " . $this->contextValueToString($value);
            }
            $parts[] = "|";
            $parts[] = implode(", ", $contextParts);
        }

        return implode(" ", $parts);
    }

    /**
     * Convert context value to string representation
     *
     * @param mixed $value Context value
     * @return string String representation
     */
    private function contextValueToString($value): string
    {
        if (is_string($value)) {
            return $value;
        } elseif (is_bool($value)) {
            return $value ? "true" : "false";
        } elseif (is_null($value)) {
            return "null";
        } elseif (is_array($value)) {
            return json_encode($value);
        } elseif (is_object($value)) {
            return method_exists($value, "__toString")
                ? (string) $value
                : get_class($value);
        } else {
            return (string) $value;
        }
    }

    /**
     * Convert level name to integer
     *
     * @param string $level Level name
     * @return int Level integer
     */
    private function levelNameToInt(string $level): int
    {
        $level = strtoupper($level);
        $mapping = [
            "DEBUG" => self::DEBUG,
            "INFO" => self::INFO,
            "WARNING" => self::WARNING,
            "ERROR" => self::ERROR,
        ];

        return $mapping[$level] ?? self::INFO;
    }

    /**
     * Create handler based on output type
     *
     * @param string $output Output type
     * @param array $config Handler configuration
     * @return LogHandler Handler instance
     */
    private function createHandler(string $output, array $config): LogHandler
    {
        switch ($output) {
            case "file":
                $path = $config["path"] ?? sys_get_temp_dir() . "/app.log";
                return new FileHandler($path);

            case "syslog":
                $identity = $config["identity"] ?? "php-app";
                $facility = $config["facility"] ?? LOG_USER;
                return new SyslogHandler($identity, $facility);

            case "null":
                return new NullHandler();

            default:
                return new NullHandler();
        }
    }

    /**
     * Get current minimum log level
     *
     * @return int Current minimum level
     */
    public function getMinLevel(): int
    {
        return $this->minLevel;
    }

    /**
     * Set minimum log level
     *
     * @param string $level New minimum level
     */
    public function setMinLevel(string $level): void
    {
        $this->minLevel = $this->levelNameToInt($level);
    }

    /**
     * Flush any buffered log messages
     */
    public function flush(): void
    {
        $this->handler->flush();
    }
}

/**
 * LogHandler Interface
 *
 * All log handlers must implement this interface
 */
interface LogHandler
{
    /**
     * Write a formatted log message
     *
     * @param string $message Formatted log message
     */
    public function write(string $message): void;

    /**
     * Flush any buffered messages
     */
    public function flush(): void;
}

/**
 * FileHandler
 *
 * Writes log messages to a file with buffering for performance
 */
class FileHandler implements LogHandler
{
    private string $path;
    private $handle;
    private array $buffer = [];
    private int $bufferSize = 100;

    /**
     * Create file handler
     *
     * @param string $path Log file path
     */
    public function __construct(string $path)
    {
        $this->path = $path;
        $this->handle = null;
    }

    /**
     * Write log message (buffered)
     *
     * @param string $message Formatted log message
     */
    public function write(string $message): void
    {
        $this->buffer[] = $message;

        // Flush when buffer is full
        if (count($this->buffer) >= $this->bufferSize) {
            $this->flush();
        }
    }

    /**
     * Flush buffer to file
     */
    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        if ($this->handle === null) {
            $this->handle = fopen($this->path, "a");
            if ($this->handle === false) {
                $this->buffer = [];
                return;
            }
        }

        foreach ($this->buffer as $message) {
            fwrite($this->handle, $message . PHP_EOL);
        }

        $this->buffer = [];
    }

    /**
     * Close file handle on destruction
     */
    public function __destruct()
    {
        $this->flush();
        if ($this->handle !== null) {
            fclose($this->handle);
        }
    }
}

/**
 * SyslogHandler
 *
 * Writes log messages to system log
 */
class SyslogHandler implements LogHandler
{
    private bool $opened = false;

    /**
     * Create syslog handler
     *
     * @param string $identity Syslog identity
     * @param int $facility Syslog facility
     */
    public function __construct(string $identity, int $facility)
    {
        openlog($identity, LOG_PID | LOG_ODELAY, $facility);
        $this->opened = true;
    }

    /**
     * Write log message to syslog
     *
     * @param string $message Formatted log message
     */
    public function write(string $message): void
    {
        if ($this->opened) {
            syslog(LOG_INFO, $message);
        }
    }

    /**
     * Flush (no-op for syslog)
     */
    public function flush(): void
    {
        // Syslog writes immediately, no buffering needed
    }

    /**
     * Close syslog on destruction
     */
    public function __destruct()
    {
        if ($this->opened) {
            closelog();
        }
    }
}

/**
 * NullHandler
 *
 * Discards all log messages (useful for testing or disabling logs)
 */
class NullHandler implements LogHandler
{
    /**
     * Write log message (discarded)
     *
     * @param string $message Formatted log message
     */
    public function write(string $message): void
    {
        // Do nothing
    }

    /**
     * Flush (no-op)
     */
    public function flush(): void
    {
        // Do nothing
    }
}
