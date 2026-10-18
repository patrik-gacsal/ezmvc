<?php

/**
 * Project-defined exception — see CLAUDE.md's "Services (optional)" section.
 * Throw this for an expected business-rule failure a Service wants a
 * Controller to catch and present gracefully, instead of a plain \Exception
 * bubbling up to index.php's top-level handler as a 500.
 */
class ValidationException extends \Exception {

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }

}