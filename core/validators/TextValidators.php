<?php
namespace Validators;

use Attribute;

/**
 * A single line of text excluding null, newline, CR, angle brackets, semicolon, and both quote characters.
 * Usable directly as a property attribute: #[Line(minLength: 5, maxLength: 20)]
 * — #[Attribute] must be re-declared on every concrete class (not inherited
 * via extends), which is why every class in validators/ repeats this line.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Line extends LengthValidator {
    public function __construct(int $minLength = 1, int $maxLength = 200, bool $optional = false) {
        parent::__construct($minLength, $maxLength, $optional);
    }

    protected function charClass(): string {
        return '[^\0\n\r<>;\'"]';
    }
}

/**
 * Free-form multi-line text — allows
 * quotes (unlike Line), still excludes null, angle brackets, and semicolon.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Text extends LengthValidator {
    public function __construct(int $minLength = 1, int $maxLength = 2000, bool $optional = false) {
        parent::__construct($minLength, $maxLength, $optional);
    }

    protected function charClass(): string {
        return '[^\0<>;]';
    }
}

/** URL-slug-safe text. */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Slug extends LengthValidator {
    public function __construct(int $minLength = 1, int $maxLength = 100, bool $optional = false) {
        parent::__construct($minLength, $maxLength, $optional);
    }

    protected function charClass(): string {
        return '[a-zA-Z0-9_-]';
    }
}
