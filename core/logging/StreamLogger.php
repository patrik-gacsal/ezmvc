<?php
namespace EzMvc;

/**
 * Writes each line to a stream, php://stderr by default: for CLI and cron
 * entry points, and for hosts that collect a process's output (Docker, PaaS).
 * The stream opens on the first write.
 */
class StreamLogger extends LineLogger {
    /** @var resource|false|null */
    private $stream = null;

    public function __construct(private string $target = 'php://stderr') {}

    protected function write(string $line): void {
        if ($this->stream ??= fopen($this->target, 'a')) {
            fwrite($this->stream, $line);
        }
    }
}
