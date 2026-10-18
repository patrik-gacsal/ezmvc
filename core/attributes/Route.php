<?php
namespace EzMvc\Attr;

use Attribute;

/** Routes GET requests matching $path (with {name} placeholders) to the method. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Get {
    public function __construct(public string $path) {}
}

/** Routes POST requests matching $path (with {name} placeholders) to the method. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Post {
    public function __construct(public string $path) {}
}

/** Routes PUT requests matching $path (with {name} placeholders) to the method. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Put {
    public function __construct(public string $path) {}
}

/** Routes DELETE requests matching $path (with {name} placeholders) to the method. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Delete {
    public function __construct(public string $path) {}
}

/** Routes PATCH requests matching $path (with {name} placeholders) to the method. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Patch {
    public function __construct(public string $path) {}
}

/**
 * Registers the method for one arbitrary HTTP method, e.g. #[Custom('/cache', 'PURGE')].
 * OPTIONS never reaches it: index.php answers every OPTIONS request with the CORS headers.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Custom {
    public function __construct(public string $path, public string $method) {}
}

/**
 * Registers the method for both GET and POST — for page controller actions
 * that serve a form (GET) and process it (POST) at the same URL.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Web {
    public function __construct(public string $path, public bool $ajax = false) {}
}
