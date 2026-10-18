<?php
namespace EzMvc;

/**
 * Appends each line to a daily file, <prefix>_Y-m-d.log, locked so concurrent
 * requests can't interleave lines. The prefix defaults to
 * Config::LOGFILE_PREFIX, then logs/log under the project root; a relative
 * one resolves against the working directory. The directory must exist.
 */
class FileLogger extends LineLogger {
    private string $pathPrefix;

    public function __construct(?string $pathPrefix = null) {
        $this->pathPrefix = $pathPrefix ?? (defined('Config::LOGFILE_PREFIX') && \Config::LOGFILE_PREFIX
            ? \Config::LOGFILE_PREFIX
            : dirname(__DIR__, 2) . '/logs/log');
    }

    protected function write(string $line): void {
        file_put_contents($this->pathPrefix . '_' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
