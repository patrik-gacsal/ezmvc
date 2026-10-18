<?php
namespace swaggergen;

class Generator {

    /** #[ApiAuth('jwt'|'apikey'|'none')] or #[ApiAuth(ApiAuth::JWT)] */
    private const AUTH_PATTERN = '~#\[ApiAuth\((?:[\'"]([^\'"]+)[\'"]|[A-Za-z0-9_]+::([A-Z_]+))\)\]~';

    /**
     * Scan the project and build the OpenAPI 3 spec JSON string.
     * DTOs (dtos/requests/responses) are processed via phpdoc @property + reflection,
     * but only ones actually reachable from an endpoint are emitted — the spec
     * shouldn't leak every entity/dto in the project, just what's exposed.
     * ApiControllers are processed via PHP 8 attribute syntax parsed as text.
     * Requires Config constants: PRODUCT, DESCRIPTION, VERSION, BASE_PATH.
     */
    public static function build(string $projectRoot, bool $verbose = false): string {
        require_once __DIR__ . '/OpenApi3.php';
        require_once $projectRoot . '/Config.php';
        require_once $projectRoot . '/core/Core.php';
        require_once $projectRoot . '/core/Router.php';
        require_once $projectRoot . '/core/validators/Validator.php';
        require_once $projectRoot . '/core/validators/LengthValidator.php';
        foreach (glob($projectRoot . '/core/validators/*.php') ?: [] as $validatorFile) {
            require_once $validatorFile;
        }
        if (file_exists($projectRoot . '/core/attributes/Swagger.php')) {
            require_once $projectRoot . '/core/attributes/Swagger.php';
        }

        $oa3 = new OpenApi3();
        $oa3->setInfo(\Config::PRODUCT, \Config::DESCRIPTION, \Config::VERSION);
        $oa3->addServer(trim(\Config::BASE_PATH, '/'), '');

        // ErrorResponse is the uniform error body every endpoint can return (the
        // ApiController boundary maps every error onto it). Register its schema so
        // the per-operation `default` responses below can $ref it.
        require_once $projectRoot . '/core/ErrorResponse.php';
        $oa3->addObject(new \EzMvc\ErrorResponse(), null, 'ErrorResponse');

        // Load DTO classes so reflection can introspect them, and index each by
        // class name so we can look up its file once we know what's referenced.
        $dtoFilesByClass = [];
        foreach (['/dtos', '/requests', '/responses'] as $subdir) {
            foreach (glob($projectRoot . $subdir . '/*.php') ?: [] as $filepath) {
                include_once $filepath;
                $className = self::classNameFromFile($filepath);
                if ($className) {
                    $dtoFilesByClass[$className] = $filepath;
                }
            }
        }

        // Also collects which DTO classes #[ApiResponse]/#[ApiRequest] reference.
        $referenced = [];
        foreach (\EzMvc\Router::readControllerFiles($projectRoot . '/controllers') as $relPath => $contents) {
            if ($verbose) {
                echo 'controller: ' . basename($relPath) . PHP_EOL;
            }
            self::processControllerFile($contents, $oa3, $verbose, $referenced);
        }

        // A referenced DTO's own properties may point at further DTOs (e.g. a
        // composite Response holding another entity's Dto) — pull those in too,
        // so the spec never has a dangling $ref, without including anything
        // that isn't actually reachable from an endpoint.
        self::expandReferencedClasses($referenced, $dtoFilesByClass);

        foreach ($dtoFilesByClass as $className => $filepath) {
            if (isset($referenced[$className])) {
                if ($verbose) {
                    echo '  dto: ' . $className . PHP_EOL;
                }
                $oa3->addObject($className, self::classDocTags($filepath));
            }
        }

        $oa3->cleanUp();
        return json_encode($oa3, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Extract just the class name from a DTO file, without registering it — used
     * to index dtoFilesByClass before we know which classes are actually referenced.
     */
    private static function classNameFromFile(string $filePath): ?string {
        foreach (explode("\n", file_get_contents($filePath)) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'class ')) {
                $words = preg_split('/[\s{}]+/', $line);
                return $words[1] ?? null;
            }
        }
        return null;
    }

    /**
     * Follow class-typed public properties of each referenced DTO to find further
     * DTOs it depends on (e.g. a composite Response holding another entity's Dto),
     * adding them to $referenced in place. Follows class-typed properties and the
     * element class of `@var T[]` arrays; a DTO referenced only through a class
     * docblock's `@property` tag isn't followed.
     */
    private static function expandReferencedClasses(array &$referenced, array $dtoFilesByClass): void {
        $queue = array_keys($referenced);
        while ($queue) {
            $className = array_shift($queue);
            if (! class_exists($className)) {
                continue;
            }
            foreach ((new \ReflectionClass($className))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
                $type     = $prop->getType();
                $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
                if ($typeName === 'array') {
                    $typeName = rtrim(\EzMvc\Core::arrayVarType($prop) ?? '', '[]');
                }
                if ($typeName && isset($dtoFilesByClass[$typeName]) && ! isset($referenced[$typeName])) {
                    $referenced[$typeName] = true;
                    $queue[]               = $typeName;
                }
            }
        }
    }

    /** The @-tag lines (e.g. @property) of the last docblock before the file's class declaration. */
    private static function classDocTags(string $filePath): array {
        $tags  = [];
        $inDoc = false;
        foreach (explode("\n", file_get_contents($filePath)) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '/**')) {
                $tags  = [];
                $inDoc = true;
            } elseif ($inDoc && str_ends_with($line, '*/')) {
                $inDoc = false;
            } elseif ($inDoc) {
                $content = trim($line, "*\t ");
                if (str_starts_with($content, '@')) {
                    $tags[] = $content;
                }
            } elseif (str_starts_with($line, 'class ')) {
                break;
            }
        }
        return $tags;
    }

    /**
     * Offset-based text scanning, no class loading: reads #[ApiTag] #[ApiAuth]
     * #[ApiResponse] #[ApiRequest] #[ApiPathParam] #[ApiQueryParam] from the
     * source; docblock text becomes the endpoint summary.
     */
    private static function processControllerFile(string $contents, OpenApi3 &$oa3, bool $verbose, array &$referenced): void {
        if (! preg_match('~class\s+(\w+)\s+extends\s+ApiController~', $contents, $cm, PREG_OFFSET_CAPTURE)) {
            return;
        }

        $classOffset = $cm[0][1];
        $className   = $cm[1][0];

        if (! str_ends_with($className, 'Controller')) {
            return;
        }

        $classSlug = \EzMvc\Core::slug(substr($className, 0, -10));
        $classDesc = self::extractDocDescription(substr($contents, 0, $classOffset));
        $classAuth = false; // false = not specified; null = explicit none; string = scheme

        foreach (self::extractAttrBlock(substr($contents, 0, $classOffset)) as $attr) {
            if (preg_match('~#\[ApiTag\([\'"]([^\'"]+)[\'"](?:,\s*[\'"]([^\'"]*)[\'"])?\)\]~', $attr, $m)) {
                $classSlug = $m[1];
                if (isset($m[2]) && $m[2] !== '') {
                    $classDesc = $m[2];
                }
            }
            if (preg_match(self::AUTH_PATTERN, $attr, $m)) {
                $classAuth = self::resolveAuth($m[1] !== '' ? $m[1] : strtolower($m[2]));
            }
        }

        if ($verbose) {
            echo '  tag: ' . $classSlug . PHP_EOL;
        }

        $oa3->addTag($classSlug, $classDesc);

        // Walk every public function inside the class body
        $classBody = substr($contents, $classOffset);

        preg_match_all('~public\s+function\s+(\w+)\s*\(~', $classBody, $fms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($fms as $fm) {
            $methodName = $fm[1][0];
            if ($methodName === 'init') {
                continue;
            }

            $beforeMethod = substr($classBody, 0, $fm[0][1]);
            self::processMethod(
                $methodName, $classSlug, $classAuth,
                self::extractDocDescription($beforeMethod),
                self::extractAttrBlock($beforeMethod),
                $oa3, $verbose, $referenced
            );
        }
    }

    /**
     * @param string|null|false $classAuth  false = not specified, null = explicit none, string = scheme
     */
    private static function processMethod(
        string $methodName,
        string $classSlug,
        string | null | false $classAuth,
        string $desc,
        array $attrs,
        OpenApi3 &$oa3,
        bool $verbose,
        array &$referenced
    ): void {
        // A public function is only an endpoint if it has a route attribute
        $verb = null;
        $path = null;
        foreach ($attrs as $attr) {
            if (preg_match('~#\[(Get|Post|Put|Delete|Patch)\([\'"]([^\'"]+)[\'"]\)\]~', $attr, $m)) {
                $verb = strtolower($m[1]);
                $path = '/' . trim($m[2], '/');
                break;
            }
        }
        if (! $verb) {
            return;
        }

        if ($verbose) {
            echo '    ' . strtoupper($verb) . ' ' . $path . ' → ' . $methodName . PHP_EOL;
        }

        $oa3->addMethod($path, $verb, $desc);
        $oa3->addMethodTag($path, $verb, $classSlug);
        // any un-listed (error) status returns the uniform ErrorResponse
        $oa3->addMethodResponse($path, $verb, createContent('ErrorResponse'), 'default');

        $methodAuth = false;

        foreach ($attrs as $attr) {
            // #[ApiResponse(Foo::class)]  or  #[ApiResponse('Foo')]  or  #[ApiResponse(Foo::class, 201)]  or  #[ApiResponse(Foo::class, 200, true)]
            if (preg_match(
                '~#\[ApiResponse\((?:([A-Za-z0-9_]+)::class|[\'"]([A-Za-z0-9_]+)[\'"])(?:,\s*(\d+))?(?:,\s*(true|false))?\)\]~',
                $attr, $m
            )) {
                $cn              = $m[1] ?: $m[2];
                $isList          = isset($m[4]) && $m[4] === 'true';
                $status          = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 200;
                $referenced[$cn] = true;
                $oa3->addMethodResponse($path, $verb, createContent($cn, $isList), $status);
            }
            // #[ApiRequest(Foo::class)]  or  #[ApiRequest('Foo')]  or  #[ApiRequest(Foo::class, true)]  or  #[ApiRequest(Foo::class, list: true)]
            if (preg_match(
                '~#\[ApiRequest\((?:([A-Za-z0-9_]+)::class|[\'"]([A-Za-z0-9_]+)[\'"])(?:,\s*(?:list\s*:\s*)?(true|false))?\)\]~',
                $attr, $m
            )) {
                $cn              = $m[1] ?: $m[2];
                $isList          = isset($m[3]) && $m[3] === 'true';
                $referenced[$cn] = true;
                $oa3->addMethodRequest($path, $verb, createContent($cn, $isList));
            }

            // #[ApiPathParam('id')]  #[ApiQueryParam('page', ApiType::INTEGER, 'Page number')]
            // #[ApiQueryParam('q', description: 'Search')] — a path param is always required
            if (preg_match('~#\[Api(Path|Query)Param\((.*)\)\]~', $attr, $m)) {
                $arguments = self::attributeArguments($m[2]);
                $name      = $arguments[0] ?? $arguments['name'] ?? '';
                $inPath    = $m[1] === 'Path';
                if ($name !== '') {
                    $oa3->addMethodParam($path, $verb, new Parameter(
                        $name,
                        $inPath ? 'path' : 'query',
                        ($arguments[1] ?? $arguments['type'] ?? '') ?: 'string',
                        $inPath,
                        $arguments[2] ?? $arguments['description'] ?? null
                    ));
                }
            }
            // method-level overrides the class default
            if (preg_match(self::AUTH_PATTERN, $attr, $m)) {
                $methodAuth = self::resolveAuth($m[1] !== '' ? $m[1] : strtolower($m[2]));
            }
        }

        $effectiveAuth = $methodAuth !== false ? $methodAuth : $classAuth;
        if ($effectiveAuth !== false) {
            $oa3->setMethodSecurity($path, $verb, $effectiveAuth);
        }

    }

    /**
     * An attribute's argument list parsed from source text: positional arguments
     * by index, named ones (`description: '…'`) by name. A quoted string may hold
     * commas and escaped quotes; `ApiType::INTEGER` becomes the constant's value
     * from EzMvc\Swagger, or its lowercased name when that class doesn't define it.
     * @return array<int|string, string>
     */
    private static function attributeArguments(string $argumentList): array {
        preg_match_all(
            '~(?:(\w+)\s*:\s*)?(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"|(\w+)::(\w+))~',
            $argumentList, $matches, PREG_SET_ORDER
        );
        $arguments = [];
        $position  = 0;
        foreach ($matches as $m) {
            if (($m[4] ?? '') !== '') {
                $constant = 'EzMvc\\Swagger\\' . $m[4] . '::' . $m[5];
                $value    = defined($constant) ? (string) constant($constant) : strtolower($m[5]);
            } else {
                $value = stripslashes(($m[2] ?? '') . ($m[3] ?? ''));
            }
            $arguments[$m[1] !== '' ? $m[1] : $position++] = $value;
        }
        return $arguments;
    }

    /**
     * Scan backward from the tail of $before, collecting contiguous #[...] lines.
     * Blank lines and lone braces are skipped; anything else stops the scan.
     * Returns attributes in their original top-to-bottom order.
     * @return string[]
     */
    private static function extractAttrBlock(string $before): array {
        $lines = array_reverse(explode("\n", rtrim($before)));
        $attrs = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#[')) {
                array_unshift($attrs, $line);
            } elseif ($line === '' || $line === '{' || $line === '}') {
                continue;
            } else {
                break;
            }

        }
        return $attrs;
    }

    /**
     * Find the last /** ... *\/ docblock in $before and return its plain-text description
     * (lines that are not PHPDoc tags).
     */
    private static function extractDocDescription(string $before): string {
        if (! preg_match_all('~/\*\*(.*?)\*/~s', $before, $matches)) {
            return '';
        }

        $desc = '';
        foreach (explode("\n", end($matches[1])) as $line) {
            $line = trim($line, "* \	\n");
            if ($line && ! str_starts_with($line, '@')) {
                $desc .= ($desc ? ' ' : '') . $line;
            }

        }
        return $desc;
    }

    /**
     * Map an ApiAuth type string to its OpenAPI security scheme name.
     * Returns false when the type is unrecognised (caller treats as "not specified").
     * Returns null for 'none' (explicit empty security array).
     * @return string|null|false
     */
    private static function resolveAuth(string $type): string | null | false {
        return match ($type) {
            'jwt', 'bearer' => 'bearerAuth',
            'apikey', 'key' => 'apikeyAuth',
            'none'  => null,
            default => false,
        };
    }
}
