<?php
namespace EzMvc\Attr;

use Attribute;

/**
 * Makes a field required, with an optional message for form requests.
 * A typed property that is non-nullable and has no default is required anyway;
 * form fields need a default for redisplay, so they declare it with this.
 * Usage: #[Required('Please enter your email')] public string $email = '';
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Required {
    public function __construct(
        public ?string $message = null
    ) {}
}

/**
 * The message for a field whose value is invalid — wrong type or format.
 * Usage: #[Validators\Email] #[ErrorMessage('Not a valid email address')]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class ErrorMessage {
    public function __construct(
        public string $message
    ) {}
}
