<?php
namespace Validators;

use Attribute;

/**
 * An integer of up to $maxDigits digits; $signed allows a leading minus.
 * Named Integer, not Int, because `int` is a reserved class name since PHP 7.
 * Usable directly as a property attribute: #[Integer(signed: true)]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Integer extends Validator {
    public function __construct(
        protected bool $signed = false,
        protected int $maxDigits = 11,
        bool $optional = false
    ) {
        parent::__construct($optional);
    }

    protected function body(): string {
        return ($this->signed ? '-?' : '') . '[0-9]{1,' . $this->maxDigits . '}';
    }
}

/**
 * A decimal number; $signed allows a leading minus.
 * Named Decimal, not Float, because `float` is a reserved class name since PHP 7.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Decimal extends Validator {
    public function __construct(protected bool $signed = false, bool $optional = false) {
        parent::__construct($optional);
    }

    protected function body(): string {
        return ($this->signed ? '-?' : '') . '[0-9]*\.?[0-9]+';
    }
}

/** Exactly $length alphanumeric characters. */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Char extends Validator {
    public function __construct(protected int $length = 1, bool $optional = false) {
        parent::__construct($optional);
    }

    protected function body(): string {
        return '[a-zA-Z0-9]{' . $this->length . '}';
    }
}

/** true/false (either case) or 1/0. */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Boolean extends Validator {
    protected function body(): string {
        return 'TRUE|FALSE|true|false|0|1';
    }
}
