<?php
namespace Validators;

/**
 * Base class for regex-pattern builders: each instance IS its compiled
 * pattern via __toString(), so it drops in anywhere a plain regex string
 * goes (preg_match(), a SchemaBinder check, etc.). $optional controls whether
 * an empty string also matches — handled once here via alternation.
 */
abstract class Validator implements \Stringable {
    public function __construct(
        protected bool $optional = false
    ) {}

    /** The pattern body only — no anchors, no delimiters. */
    abstract protected function body(): string;

    public function __toString(): string {
        return $this->optional
            ? '~^(?:' . $this->body() . ')$|^$~'
            : '~^(?:' . $this->body() . ')$~';
    }
}
