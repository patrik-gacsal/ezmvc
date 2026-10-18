<?php
namespace EzMvc\Swagger;

use Attribute;

// Swagger documentation attributes for ezmvc ApiControllers.
// PHP does not validate attribute classes until newInstance() is called,
// so including this file is optional at runtime — it exists for IDE autocomplete
// and for tooling that uses Reflection.

/**
 * Tags a controller with a Swagger group name and optional description.
 * Without this attribute the tag name is derived from the class name.
 * Usage: #[ApiTag('users', 'User management')]
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ApiTag {
    public function __construct(
        public string $name,
        public string $description = ''
    ) {}
}

/**
 * Sets the authentication requirement for a controller class or a single method.
 * A method-level attribute overrides the class-level default.
 * Usage: #[ApiAuth(ApiAuth::JWT)]  #[ApiAuth('apikey')]  #[ApiAuth('none')]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class ApiAuth {
    const JWT    = 'jwt';
    const APIKEY = 'apikey';
    const NONE   = 'none';

    public function __construct(
        public string $type
    ) {}
}

/**
 * Documents the response body of an endpoint.
 * Use $list = true for array responses (generates a UserResponse[] schema reference).
 * Usage: #[ApiResponse(UserResponse::class)]
 *        #[ApiResponse(UserResponse::class, 200, true)]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class ApiResponse {
    public function __construct(
        public string $class,
        public int $status = 200,
        public bool $list = false
    ) {}
}

/**
 * Documents the request body of an endpoint.
 * Usage: #[ApiRequest(CreateUserRequest::class)]
 */
#[Attribute(Attribute::TARGET_METHOD)]
class ApiRequest {
    public function __construct(
        public string $class,
        public bool $list = false,
    ) {}
}

/**
 * The types a path or query parameter can have in the Swagger spec — the
 * $type of ApiPathParam/ApiQueryParam: #[ApiQueryParam('page', ApiType::INTEGER)].
 */
final class ApiType {
    const STRING  = 'string';
    const INTEGER = 'integer';
    const NUMBER  = 'number';
    const BOOLEAN = 'boolean';
}

/**
 * Documents a required path parameter (always required — it is part of the URL).
 * Usage: #[ApiPathParam('id')]                                   — a string
 *        #[ApiPathParam('id', ApiType::INTEGER, 'User ID')]
 *        #[ApiPathParam('slug', description: 'URL name')]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class ApiPathParam {
    public function __construct(
        public string $name,
        public string $type = ApiType::STRING,
        public string $description = ''
    ) {}
}

/**
 * Documents an optional query parameter.
 * Usage: #[ApiQueryParam('search')]                              — a string
 *        #[ApiQueryParam('page', ApiType::INTEGER, 'Page number')]
 *        #[ApiQueryParam('active', type: ApiType::BOOLEAN)]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class ApiQueryParam {
    public function __construct(
        public string $name,
        public string $type = ApiType::STRING,
        public string $description = ''
    ) {}
}
