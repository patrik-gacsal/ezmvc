<?php
namespace Validators;

/**
 * Base for validators whose body is a single character class repeated
 * within a length range, e.g. Line, Text, Slug.
 */
abstract class LengthValidator extends Validator {
    public function __construct(
        protected int $minLength = 1,
        protected int $maxLength = 255,
        bool $optional = false
    ) {
        parent::__construct($optional);
    }

    /** The character class only, e.g. '[a-zA-Z0-9]'. */
    abstract protected function charClass(): string;

    protected function body(): string {
        return $this->charClass() . '{' . $this->minLength . ',' . $this->maxLength . '}';
    }
}
