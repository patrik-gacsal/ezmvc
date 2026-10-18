<?php
namespace Validators;

use Attribute;

/** An email address. Usable directly as a property attribute: #[Email]. */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Email extends Validator {
    protected function body(): string {
        return '[A-Za-z0-9.]+@[a-zA-Z0-9-]+\.[a-zA-Z]{2,5}';
    }
}

/** E.164-ish phone number. */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Phone extends Validator {
    protected function body(): string {
        return '\+[1-9][0-9]{9,12}';
    }
}

/** Tax number shaped 12345678-1-12. */
#[Attribute(Attribute::TARGET_PROPERTY)]
class TaxNumber extends Validator {
    protected function body(): string {
        return '[0-9]{8}-[0-9]{1}-[0-9]{2}';
    }
}

/**
 * Hex digest of a fixed length: md5 (32), sha1 (40), sha256 (64, the default), etc.
 * Usable directly as a property attribute: #[Hash(length: 32)]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Hash extends Validator {
    public function __construct(protected int $length = 64, bool $optional = false) {
        parent::__construct($optional);
    }

    protected function body(): string {
        return '[0-9a-fA-F]{' . $this->length . '}';
    }
}

/** A positive integer id without leading zeros, up to 11 digits. */
#[Attribute(Attribute::TARGET_PROPERTY)]
class IdNumber extends Validator {
    protected function body(): string {
        return '[1-9][0-9]{0,10}';
    }
}

/** Identifier starting with a letter. */
#[Attribute(Attribute::TARGET_PROPERTY)]
class IdText extends Validator {
    public function __construct(protected int $maxLength = 64, bool $optional = false) {
        parent::__construct($optional);
    }

    protected function body(): string {
        return '[a-zA-Z][0-9A-Za-z_-]{0,' . ($this->maxLength - 1) . '}';
    }
}

/**
 * A persisted random-string refresh token: 64 alphanumerics plus a numeric id
 * suffix. JWT refresh tokens are verified by Jwt::parse() instead.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class RefreshToken extends Validator {
    protected function body(): string {
        return '[0-9a-zA-Z]{64}[1-9][0-9]{0,10}';
    }
}

/** ISO-639-1-shaped 2-letter code. */
#[Attribute(Attribute::TARGET_PROPERTY)]
class LanguageCode extends Validator {
    protected function body(): string {
        return '[a-zA-Z]{2}';
    }
}
