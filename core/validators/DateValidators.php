<?php
namespace Validators;

use Attribute;

/** YYYY-MM-DD. Usable directly as a property attribute: #[Date]. */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Date extends Validator {
    protected function body(): string {
        return '[0-9]{4}-[0-9]{2}-[0-9]{2}';
    }
}

/** HH:MM:SS. */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Time extends Validator {
    protected function body(): string {
        return '[0-9]{2}:[0-9]{2}:[0-9]{2}';
    }
}

/**
 * YYYY-MM-DD HH:MM:SS. Named DateTime same as the
 * built-in global \DateTime class — safe since this lives in the
 * Validators namespace, but always import it explicitly (use Validators\DateTime;)
 * rather than referencing it unqualified next to the global one.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class DateTime extends Validator {
    protected function body(): string {
        return '[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}';
    }
}

/** 0-23. */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Hour extends Validator {
    protected function body(): string {
        return '0?[0-9]|1[0-9]|2[0-3]';
    }
}

/** 0-59. */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Minute extends Validator {
    protected function body(): string {
        return '0?[0-9]|[0-5][0-9]';
    }
}
