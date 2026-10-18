<?php
namespace EzMvc;

class Router {

    private static array $routeParams   = [];
    private static ?array $matchedRoute = null;
    private const ROUTES_FILE = 'generated/routes.php';

    /**
     * CSS classes identifying the currently matched route's page —
     * ['controller-<slug>', 'action-<slug>'] — for a page <body>. This is what
     * PageView uses by default. Empty when no route matched (e.g. the catch-all
     * fallback, which passes bodyClassesFor() with the default controller/action
     * instead).
     * @return string[]
     */
    public static function bodyClasses(): array {
        return self::$matchedRoute
            ? self::bodyClassesFor(self::$matchedRoute['controller'], self::$matchedRoute['method'])
            : [];
    }

    /**
     * CSS classes for an arbitrary controller/action pair (pure, no match
     * state): ['controller-<slug>', 'action-<slug>'], with the 'Controller'
     * suffix dropped and 'action-…' omitted when $action is empty.
     * @return string[]
     */
    public static function bodyClassesFor(string $controllerClass, ?string $action): array {
        $classes = ['controller-' . Core::slug(preg_replace('/Controller$/', '', $controllerClass))];
        if ($action !== null && $action !== '') {
            $classes[] = 'action-' . Core::slug($action);
        }
        return $classes;
    }

    /**
     * Match incoming request against the pre-built route table.
     * Returns the matched route array or null.
     * @return array|null
     */
    public static function match(string $method, string $uri): ?array {
        if (! file_exists(self::ROUTES_FILE)) {
            return null;
        }

        $routes = require self::ROUTES_FILE;
        foreach ($routes[$method] ?? [] as $route) {
            if (preg_match($route['regex'], $uri, $matches)) {
                self::$routeParams  = [];
                self::$matchedRoute = $route;
                foreach ($route['params'] as $i => $name) {
                    self::$routeParams[$name] = $matches[$i + 1];
                }
                return $route;
            }
        }
        return null;
    }

    /** A route parameter of the current request, for code outside the controller. */
    public static function getParam(string $name): ?string {
        return self::$routeParams[$name] ?? null;
    }

    /**
     * @return string[]
     */
    public static function getParams(): array {
        return self::$routeParams;
    }

    /**
     * Read all (Api|Page)Controller PHP files from a directory tree.
     * Returns [ 'sub/FooController.php' => file_content ] keyed by path relative
     * to $controllersDir, sorted alphabetically.
     * @return array<string, string>
     */
    public static function readControllerFiles(string $controllersDir = 'controllers/'): array {
        $result = [];
        $dir    = rtrim($controllersDir, '/\\');
        if (! is_dir($dir)) {
            return $result;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if (! preg_match('~class\s+\w+\s+extends\s+(Api|Page)Controller~', $content)) {
                continue;
            }
            $relPath          = ltrim(str_replace([$dir, '\\'], ['', '/'], $file->getPathname()), '/');
            $result[$relPath] = $content;
        }
        ksort($result);
        return $result;
    }

    /**
     * Parse all ApiController files and build the route table.
     * Uses text-based attribute parsing — safe to call from the CLI without
     * a database or Config (no framework bootstrap required).
     * @return array<string, array>
     */
    public static function build(string $controllersDir = 'controllers/'): array {
        $routes = [];
        // attribute pattern => fn(match) => [http verbs, path, ajax]
        $attributeParsers = [
            '~#\[(Get|Post|Put|Delete|Patch)\([\'"](.*?)[\'"]\)\]~'
                => fn($m) => [[strtoupper($m[1][0])], $m[2][0], false],
            '~#\[Web\([\'"](.*?)[\'"](?:\s*,\s*(?:ajax\s*:\s*)?(true|false))?\)\]~'
                => fn($m) => [['GET', 'POST'], $m[1][0], ($m[2][0] ?? '') === 'true'],
            '~#\[Custom\([\'"](.*?)[\'"]\s*,\s*(?:method\s*:\s*)?[\'"]([A-Za-z]+)[\'"]\)\]~'
                => fn($m) => [[strtoupper($m[2][0])], $m[1][0], false],
        ];
        $methodPattern = '~public\s+function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(~';

        foreach (self::readControllerFiles($controllersDir) as $relPath => $content) {
            foreach ($attributeParsers as $attributePattern => $parse) {
                preg_match_all($attributePattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                foreach ($matches as $m) {
                    $afterAttribute = $m[0][1] + strlen($m[0][0]);
                    if (! preg_match($methodPattern, $content, $methodMatch, 0, $afterAttribute) || $methodMatch[1] === 'init') {
                        continue;
                    }
                    [$httpVerbs, $path, $ajax] = $parse($m);
                    $path = trim($path, '/');
                    foreach ($httpVerbs as $httpVerb) {
                        $routes[$httpVerb][] = [
                            'regex'      => self::pathToRegex($path),
                            'params'     => self::extractParams($path),
                            'controller' => basename($relPath, '.php'),
                            'file'       => $relPath,
                            'method'     => $methodMatch[1],
                            'path'       => $path,
                            'ajax'       => $ajax,
                        ];
                    }
                }
            }
        }

        foreach ($routes as $httpVerb => $list) {
            $routes[$httpVerb] = self::sortBySpecificity($list);
        }

        return $routes;
    }

    /**
     * Orders one verb's routes so a literal segment always beats a placeholder
     * in the same position: 'api-test/all' is matched before 'api-test/{id}'
     * no matter which order the attributes were declared in. The comparison
     * walks the segments left to right and stops at the first position where
     * one path is literal and the other is not; routes that never differ that
     * way keep their declaration order (PHP's sort is stable as of 8.0).
     *
     * Done once here, at build time, so match() stays a plain first-hit loop
     * over the generated table with no per-request ordering work.
     * @param array[] $routes
     * @return array[]
     */
    private static function sortBySpecificity(array $routes): array {
        usort($routes, function (array $left, array $right): int {
            $leftSegments  = explode('/', $left['path']);
            $rightSegments = explode('/', $right['path']);
            $sharedDepth   = min(count($leftSegments), count($rightSegments));
            for ($i = 0; $i < $sharedDepth; $i++) {
                $leftIsLiteral  = ! str_contains($leftSegments[$i], '{');
                $rightIsLiteral = ! str_contains($rightSegments[$i], '{');
                if ($leftIsLiteral !== $rightIsLiteral) {
                    return $leftIsLiteral ? -1 : 1;
                }
            }
            return 0;
        });
        return $routes;
    }

    /**
     * Routes that no ordering can rescue: a second route on the same verb whose
     * pattern is identical to an earlier one, so it can never be reached. Unlike
     * a literal-vs-placeholder overlap this is always a mistake, so `ezmvc
     * generate` reports it.
     * @param array[] $routes the table from build()
     * @return array<string, array<int, array{0: array, 1: array}>> [verb => [[winner, unreachable], …]]
     */
    public static function findDuplicates(array $routes): array {
        $duplicates = [];
        foreach ($routes as $httpVerb => $list) {
            $seenByPattern = [];
            foreach ($list as $route) {
                if (isset($seenByPattern[$route['regex']])) {
                    $duplicates[$httpVerb][] = [$seenByPattern[$route['regex']], $route];
                    continue;
                }
                $seenByPattern[$route['regex']] = $route;
            }
        }
        return $duplicates;
    }

    /**
     * Write the route table to the generated routes file.
     */
    public static function save(array $routes, string $file = self::ROUTES_FILE): void {
        $export = var_export($routes, true);
        file_put_contents($file, "<?php\nreturn " . $export . ";\n");
    }

    private static function pathToRegex(string $path): string {
        $parts = preg_split('~\{[a-zA-Z_][a-zA-Z0-9_]*\}~', $path);
        return '~^' . implode('([^/]+)', array_map(fn($p) => preg_quote($p, '~'), $parts)) . '$~';
    }

    /**
     * @return string[]
     */
    private static function extractParams(string $path): array {
        preg_match_all('~\{([a-zA-Z_][a-zA-Z0-9_]*)\}~', $path, $matches);
        return $matches[1];
    }
}
