<?php
namespace EzMvc;

use Validators\Validator;

abstract class ApiController extends Controller {
    const AUTH_NONE       = 0;
    const AUTH_APIKEY     = 1;
    const AUTH_APIKEY_ALT = 2;
    const AUTH_JWT        = 3;
    const AUTH_JWT_NOEXP  = 4;

    protected ?string $uid                   = null, $secret                   = null;
    protected object|array|null $request = null;
    protected ?object $accessTokenPayload    = null;

    private static $defaultAuthType             = 0;
    private static $enabledMethods              = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];
    private static ?string $defaultSecret       = null;
    private static ?string $defaultJwtAlgorithm = null;
    private static int $defaultCacheSecs        = 0;
    private ?int $cacheSecs                     = null;
    private int|null $authType                = null;
    private ?string $jwtAlgorithm               = null;
    /**
     * Sets the decoded request body.
     * @param object|array|null $request
     */
    public function setRequest($request): void {
        $this->request = $request;
    }

    /**
     * sets secret for authentication
     * @param string $secret
     */
    public function setSecret(#[\SensitiveParameter] $secret) {
        $this->secret = $secret;
    }
    /**
     * sets default secret for authentication
     * @param string $secret
     */
    public static function setDefaultSecret(#[\SensitiveParameter] string $secret) {
        self::$defaultSecret = $secret;
    }
    /**
     * gets raw request body
     * @return string (binary)
     */
    protected function getRawRequestBody() {
        return file_get_contents("php://input");
    }
    /**
     * Called by the framework dispatcher: reads #[ApiRequest(Foo::class)] from the
     * action method, validates the request body against that schema, stores the
     * populated object in $this->request, and returns it as the action's argument —
     * so the action can declare it typed: post(Foo $request), or with list: true,
     * postMany(array $requests). Returns no arguments when the action has no
     * #[ApiRequest] or its class is not loaded.
     * @return array the arguments to call the action with
     */
    public function performRequestCheck(string $action): array {
        foreach ((new \ReflectionMethod($this, $action))->getAttributes() as $attr) {
            if (basename(str_replace('\\', '/', $attr->getName())) === 'ApiRequest') {
                $arguments = $attr->getArguments();
                $class     = $arguments[0] ?? null;
                // second positional arg, or the named form #[ApiRequest(Foo::class, list: true)]
                $isList = $arguments[1] ?? $arguments['list'] ?? false;
                if (! $class || ! class_exists($class)) {
                    return [];
                }
                $this->request = $isList
                    ? $this->checkRequestAsListOf(new $class())
                    : $this->checkRequestAs(new $class());
                return [$this->request];
            }
        }
        return [];
    }

    /**
     * Validates the decoded JSON body into $schema and returns it.
     *
     * Typed properties: non-nullable without a default is required (missing → 400);
     * otherwise a missing field keeps its default, or null when it has none. The
     * value is cast to the declared type, and any Validators\* attribute on the
     * property adds a regex check: #[Validators\Line(minLength: 5, maxLength: 20)].
     * A class-typed property takes a nested object validated into that class; an
     * array property with a `/** @var Foo[] *\/` docblock (int[], Foo[][] …) has every
     * element checked; an array without @var takes any JSON array.
     *
     * Untyped properties are the legacy mode: the default value is a regex, a
     * Validators\* class name, a nested schema object, or [] (see checkField()).
     */
    protected function checkRequestAs(object $schema): object {
        if ($this->request === null) {
            throw ApiException::badRequest('No request');
        }
        if (! is_object($this->request)) {
            throw ApiException::badRequest('Request is not an object');
        }

        return self::populateSchema($schema, $this->request);
    }

    /**
     * The same validation as checkRequestAs(), applied to every element of a
     * JSON array body — what #[ApiRequest(Foo::class, list: true)] selects.
     * Each element is populated into its own instance of the schema class, so
     * an array body is checked exactly as strictly as a single one.
     *
     * A failing element's message is prefixed with its index: 'Missing required
     * field: name' identifies nothing in a body of fifty objects.
     * @param object $schema an instance of the class each element is validated against
     * @param ?array $list the elements to validate instead of the body — e.g. an
     *   array property with no @var element type: ($this->request->lines)
     * @return object[]
     */
    protected function checkRequestAsListOf(object $schema, ?array $list = null): array {
        if ($list === null) {
            if ($this->request === null) {
                throw ApiException::badRequest('No request');
            }
            // json_decode gives objects for JSON objects, so an array here is a JSON array
            if (! is_array($this->request)) {
                throw ApiException::badRequest('Request is not an array');
            }
            $list = $this->request;
        }

        $schemaClass = get_class($schema);
        $items       = [];
        foreach ($list as $index => $element) {
            if (! is_object($element)) {
                throw ApiException::badRequest('[' . $index . ']: not an object');
            }
            try {
                $items[] = self::populateSchema(new $schemaClass(), $element);
            } catch (ApiException $e) {
                throw ApiException::badRequest('[' . $index . ']: ' . $e->getMessage());
            }
        }
        return $items;
    }

    /**
     * Validates one decoded body object into one schema instance and returns it;
     * the first error becomes a 400. The rules live in SchemaBinder, shared with
     * form requests; the legacy untyped properties are checked here (checkField()).
     */
    private static function populateSchema(object $schema, object $body): object {
        $errors = SchemaBinder::bind($schema, $body, untyped: function (\ReflectionProperty $prop, object $schema, object $body) {
            $name = $prop->getName();
            self::checkField($prop->getValue($schema), $body, $name);
            $schema->$name = $body->$name;
        });
        if ($errors) {
            throw ApiException::badRequest($errors[0]["customMessage"] ?? $errors[0]["message"]);
        }
        return $schema;
    }

    /**
     * Legacy check of one untyped field: $pattern is a regex, a Validators\*
     * class name, a nested schema object, or an array (the field defaults to []).
     */
    private static function checkField(mixed $pattern, object $object, string $field): void {
        if (is_object($pattern)) {
            if (! property_exists($object, $field)) {
                $object->$field = (object) [];
            }
            if (! is_object($object->$field)) {
                throw ApiException::badRequest('Not an object (' . get_class($pattern) . '): ' . $field);
            }
            foreach (get_object_vars($pattern) as $nestedField => $nestedPattern) {
                self::checkField($nestedPattern, $object->$field, $nestedField);
            }
            return;
        }
        if (is_array($pattern)) {
            if ($pattern) {
                throw new \LogicException('Untyped array patterns are not supported (' . $field . '); declare a typed property instead.');
            }
            if (! property_exists($object, $field)) {
                $object->$field = [];
            }
            return;
        }
        if (! property_exists($object, $field)) {
            $object->$field = null;
        }
        if (! $pattern) {
            return;
        }
        if (is_string($pattern) && class_exists($pattern) && is_subclass_of($pattern, Validator::class)) {
            $pattern = (string) new $pattern();
        }
        if (! preg_match($pattern, $object->$field)) {
            throw ApiException::badRequest('Invalid field value: ' . $field . '->' . $object->$field);
        }
    }

    /** The auth secret in effect: this controller's, else the default. */
    protected function getSecret() {
        return $this->secret ?: self::$defaultSecret;
    }

    /** Default cache duration (seconds) for every API response; 0 = no-cache. Set in app.php. */
    public static function setDefaultCacheSecs(int $secs): void {
        self::$defaultCacheSecs = $secs;
    }

    /** Per-controller cache duration (seconds), overriding the default. Call in init(). */
    protected function setCacheSecs(int $secs): void {
        $this->cacheSecs = $secs;
    }

    /** The Cache-Control value for this controller's responses: `max-age=N`, or `no-cache`. */
    public function getCacheControl(): string {
        $secs = $this->cacheSecs ?? self::$defaultCacheSecs;
        return $secs > 0 ? 'max-age=' . $secs : 'no-cache';
    }

    /**
     * Reads a GET (or GET-then-POST, when $post) parameter, optionally validated
     * against a Validators\* instance — e.g. $this->getParam('email', new Validators\Email()).
     * Any Stringable works (a validator stringifies to its own regex and encodes
     * its length limits); a plain regex string works too. Returns the value, or
     * null if the parameter is absent or fails validation.
     * @param string $name
     * @param \Stringable|string|null $validator e.g. new Validators\Line(minLength: 1, maxLength: 20)
     * @param bool $post also consult $_POST when the value isn't in $_GET
     * @return ?string
     */
    protected function getParam(string $name, $validator = null, bool $post = false): ?string {
        $value = self::inputValue($name, $post);
        if ($value === null || ($validator !== null && ! preg_match((string) $validator, $value))) {
            return null;
        }
        return $value;
    }
    /**
     * gets a boolean parameter
     * @param string $name
     * @param string $default
     * @return boolean
     */
    protected function getBoolean($name, $default = null, $post = false) {
        $value = self::inputValue($name, $post);
        return $value === null ? $default : $value && strtolower($value) !== 'false';
    }
    /**
     * get an integer parameter
     * @param string $name
     * @param string $default
     * @return int
     */
    protected function getInt($name, $default = null, $post = false) {
        $value = self::inputValue($name, $post);
        return $value === null ? $default : (int) $value;
    }

    /** The GET value, or the POST value when $post and GET lacks it. */
    private static function inputValue(string $name, bool $post) {
        return $_GET[$name] ?? ($post ? $_POST[$name] ?? null : null);
    }

    /* OVERRIDABLE METHODS */

    /**
     * sets default authentication type.
     *  @param mixed $auth
     */
    public static function setDefaultAuthType($auth) {
        self::$defaultAuthType = $auth;
    }
    /**
     * sets authentication type
     * @param int $auth
     */
    public function setAuthType($auth) {
        $this->authType = $auth;
    }
    /**
     * set user id for latter use
     * @param string $uid
     */
    public function setUid($uid) {
        $this->uid = $uid;
    }
    /**
     * returns auth type
     * @return int
     */
    private function getAuthType() {
        return $this->authType ?? self::$defaultAuthType;
    }
    /**
     * Sets the default JWT algorithm for every ApiController (call in app.php).
     * Jwt::ALG_HS256 (the default) treats the auth secret as a shared HMAC
     * secret; Jwt::ALG_RS256 treats it as the issuer's PEM public key — so for
     * RS256, set the secret (setDefaultSecret / setSecret) to that public key.
     * @param string $alg Jwt::ALG_HS256 or Jwt::ALG_RS256
     */
    public static function setDefaultJwtAlgorithm(string $alg): void {
        self::$defaultJwtAlgorithm = $alg;
    }
    /**
     * Per-controller JWT algorithm, overriding the default. Call in init().
     * @param string $alg Jwt::ALG_HS256 or Jwt::ALG_RS256
     */
    public function setJwtAlgorithm(string $alg): void {
        $this->jwtAlgorithm = $alg;
    }
    /**
     * The JWT algorithm in effect: the per-controller override, else the app
     * default, else HS256.
     * @return string
     */
    private function getJwtAlgorithm(): string {
        return $this->jwtAlgorithm ?? self::$defaultJwtAlgorithm ?? Jwt::ALG_HS256;
    }

    /**
     * tries to authenticate the request.
     * returns true (APIKEY/NONE), false (failure), or the decoded JWT payload
     * object in case of a successful JWT.
     * @return mixed
     */
    protected function auth(#[\SensitiveParameter] $secret = false) {
        if (! $secret && $this->getSecret()) {
            $secret = $this->getSecret();
        }
        $noexp = 0;
        switch ($this->getAuthType()) {
            case self::AUTH_NONE:
                return true;
            case self::AUTH_APIKEY:
                $apikey = $_SERVER['HTTP_APIKEY'] ?? null;
                return $apikey !== null && hash_equals($secret, $apikey);
            case self::AUTH_APIKEY_ALT: // hashed apikey on server
                $apikey = $_SERVER['HTTP_APIKEY'] ?? null;
                return $apikey !== null && hash_equals($secret, hash('sha256', $apikey));
            case self::AUTH_JWT_NOEXP:
                $noexp = 1;
            case self::AUTH_JWT:
                $auth  = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
                $token = $auth ? substr($auth, strlen('Bearer ')) : null;
                if (! $token) {
                    return false;
                }

                return Jwt::parse($token, $secret, $noexp, $this->getJwtAlgorithm());
        }
        return false;
    }

    /**
     * Authenticates the request per the configured auth type; throws
     * ApiException::unauthorized() (→ 401) on failure. On a successful JWT it
     * stores the decoded payload ($this->accessTokenPayload) and its `sub` claim
     * as the uid ($this->uid), so an action can read any claim plus the common uid.
     * Called by the dispatcher before the action.
     */
    public function authenticate(): void {
        $result = $this->auth($this->getSecret());
        if (! $result) {
            throw ApiException::unauthorized();
        }
        // JWT auth returns the decoded payload object; APIKEY/NONE return true.
        if (is_object($result)) {
            $this->accessTokenPayload = $result;
            $this->setUid($result->sub ?? null);
        }
    }
    /**
     * Returns enabled request methods.
     * @return string[]
     */
    public static function getEnabledMethods() {
        return self::$enabledMethods;
    }
    /**
     * Sets enabled request methods.
     * @param string[] $methods
     */
    public static function setEnabledMethods($methods) {
        self::$enabledMethods = $methods;
    }

}
