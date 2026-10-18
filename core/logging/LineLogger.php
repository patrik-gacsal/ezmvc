<?php
namespace EzMvc;

/**
 * Base of the built-in loggers. Drops an entry below Core's minimum level and
 * hands the rest to write() as one line:
 *   2026.09.27. 10:40:57 [::1] [6225bf60] [info] [REQ]: GET /users
 * Newlines become spaces, so an entry can't forge another line. The context
 * left after 'category' follows as JSON, a Throwable in it as
 * "Class: message @ file:line".
 */
abstract class LineLogger implements Logger {
    abstract protected function write(string $line): void;

    public function log($level, string|\Stringable $message, array $context = []): void {
        if (! Core::isLogged($level)) {
            return;
        }
        $entry = '[' . $level . '] [' . ($context['category'] ?? 'app') . ']: ' . $message;
        unset($context['category']);
        if ($context) {
            $entry .= ' ' . json_encode(array_map(
                fn($value) => $value instanceof \Throwable
                    ? get_class($value) . ': ' . $value->getMessage() . ' @ ' . $value->getFile() . ':' . $value->getLine()
                    : $value,
                $context
            ), JSON_UNESCAPED_UNICODE);
        }
        $this->write(date('Y.m.d. H:i:s') . ' [' . ($_SERVER['REMOTE_ADDR'] ?? '-') . '] [' . Core::requestId() . '] '
            . str_replace(["\r\n", "\n", "\r"], ' ', $entry) . "\n");
    }

    public function emergency(string|\Stringable $message, array $context = []): void {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string|\Stringable $message, array $context = []): void {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(string|\Stringable $message, array $context = []): void {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(string|\Stringable $message, array $context = []): void {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(string|\Stringable $message, array $context = []): void {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice(string|\Stringable $message, array $context = []): void {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info(string|\Stringable $message, array $context = []): void {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug(string|\Stringable $message, array $context = []): void {
        $this->log(LogLevel::DEBUG, $message, $context);
    }
}
