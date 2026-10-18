<?php
namespace EzMvc;

/**
 * A log backend, installed with Core::setLogger(): FileLogger, StreamLogger,
 * or your own. The methods carry psr/log 3's exact signatures, so any PSR-3
 * logger class becomes one with `class AppLogger extends Psr3Logger implements
 * \EzMvc\Logger {}`. An entry's category travels in $context['category'].
 */
interface Logger {
    public function emergency(string|\Stringable $message, array $context = []): void;
    public function alert(string|\Stringable $message, array $context = []): void;
    public function critical(string|\Stringable $message, array $context = []): void;
    public function error(string|\Stringable $message, array $context = []): void;
    public function warning(string|\Stringable $message, array $context = []): void;
    public function notice(string|\Stringable $message, array $context = []): void;
    public function info(string|\Stringable $message, array $context = []): void;
    public function debug(string|\Stringable $message, array $context = []): void;
    /** @param string $level a LogLevel constant */
    public function log($level, string|\Stringable $message, array $context = []): void;
}
