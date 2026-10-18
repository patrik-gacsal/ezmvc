<?php
namespace EzMvc;

/**
 * Framework-wide runtime helpers: the debug flag, the logging facade,
 * a top-level shutdown error handler, and redirect. Static-only.
 */
final class Core {
    /** Keys whose values redactSecrets() hides; matched as a substring, case-insensitively. */
    const SECRET_KEYS = 'password|passwd|secret|token|apikey|api_key|csrf';

    private static $debug = false;
    private static string $requestId = '-';
    private static ?Logger $logger = null;
    private static ?string $minimumLevel = null;
    private static ?string $pendingRequestLine = null;

    private function __construct() {}

    /**
     * Starts request logging, called once from index.php before app.php: gives
     * the request an id that every log line carries, queues the REQ line for
     * logRequest(), and on shutdown — after errorHandler() — logs a RES line
     * with the status and duration. A REQ without its RES marks a request the
     * process never finished.
     */
    public static function init() {
        self::$requestId          = bin2hex(random_bytes(4));
        self::$pendingRequestLine = ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . self::redactSecrets($_SERVER['REQUEST_URI'] ?? '');
        register_shutdown_function([self::class, 'errorHandler']);
        register_shutdown_function(fn() => self::log(
            http_response_code() . ' ' . round((microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000) . 'ms',
            LogLevel::INFO,
            'RES'
        ));
    }

    /**
     * Writes the REQ line queued by init(), once. index.php calls it right
     * after app.php, so the line reaches the logger app.php chose; log() calls
     * it first too, so REQ stays the request's first line even when app.php
     * logs something or dies.
     */
    public static function logRequest(): void {
        if (self::$pendingRequestLine !== null) {
            $requestLine              = self::$pendingRequestLine;
            self::$pendingRequestLine = null;
            self::logger()->log(LogLevel::INFO, $requestLine, ['category' => 'REQ']);
        }
    }

    /** This request's log id, e.g. to show on an error page so a report can be traced. */
    public static function requestId(): string {
        return self::$requestId;
    }

    /**
     * $text with the values of secret-looking keys replaced by ***: JSON
     * ("password": "…") and form/query strings (token=…). A plain regex pass —
     * no parsing — for log lines only.
     */
    public static function redactSecrets(string $text): string {
        $keys = self::SECRET_KEYS;
        $text = preg_replace('~("[^"]*(?:' . $keys . ')[^"]*"\s*:\s*)("(?:[^"\\\\]|\\\\.)*"|[^,}\]\s]+)~i', '$1"***"', $text);
        return preg_replace('~((?:^|[?&])[^=&?]*(?:' . $keys . ')[^=&]*=)[^&]*~i', '$1***', $text);
    }
    /**
     * Sends a Location redirect and halts. Call it from a controller, service,
     * model, or anywhere. By default an off-site target is refused (rewritten to
     * '/') so a user-supplied $url can't be turned into an open-redirect /
     * phishing link — pass $allowExternal = true for a deliberate external
     * redirect (OAuth, payment gateway, …). Control chars are stripped either way.
     * @param string $url
     * @param bool $allowExternal permit redirecting to another host
     */
    public static function redirect(string $url, bool $allowExternal = false): void {
        // every control char: browsers drop tabs from a URL, so "/\t/evil.com" would mean //evil.com
        $url = preg_replace('~[\x00-\x1F\x7F]~', '', $url);
        if (! $allowExternal && self::isExternalUrl($url)) {
            $url = '/';
        }
        header('Location: ' . $url);
        die();
    }

    /**
     * True unless $url is a path on this site: protocol-relative //host, any
     * scheme — "http:evil.com" is absolute to a browser on an https page — or
     * a URL too malformed to parse all count as external.
     */
    private static function isExternalUrl(string $url): bool {
        $url = ltrim($url);
        if (preg_match('~^[/\\\\]{2,}~', $url)) {
            return true;
        }
        $parts = parse_url(str_replace('\\', '/', $url));
        return $parts === false || isset($parts['scheme']) || isset($parts['host']);
    }

    /**
     * The `T[]` type in a property's `/** @var … *\/` docblock — 'OrderLineDto[]',
     * 'int[][]' — without `?`, `|null` or a leading `\`; null when there is no
     * such @var. Read by request validation and the Swagger generator.
     */
    public static function arrayVarType(\ReflectionProperty $property): ?string {
        if (! preg_match('~@var\s+([^\s*]+)~', (string) $property->getDocComment(), $match)) {
            return null;
        }
        $type = ltrim(preg_replace('~^\?|\|null$|^null\|~i', '', $match[1]), '\\');
        return str_ends_with($type, '[]') ? $type : null;
    }

    /**
     * Slugifies a name: 'ThisIsAName' → 'this-is-a-name'. The single slugger
     * behind route slugs (Router::name2slug), page <body> classes
     * (Router::bodyClassesFor) and per-view CSS classes (View::cssClass).
     * @param string $name
     * @return string
     */
    public static function slug(string $name): string {
        if (strlen($name) > 0) {
            $name = strtolower($name[0]) . substr($name, 1);
        }
        return strtolower(preg_replace('/([A-Z])/', '-$1', $name));
    }
    /**
     * Logs $entry through logger() at a LogLevel, under $category (REQ, RES,
     * HttpClient, … — 'app' by default).
     * @param string|\Stringable|array|object $entry arrays and other objects
     *   are logged as compact JSON
     */
    public static function log($entry, string $level = LogLevel::INFO, string $category = 'app', array $context = []): void {
        self::logRequest();
        $entry = is_array($entry) || (is_object($entry) && ! $entry instanceof \Stringable)
            ? json_encode($entry, JSON_UNESCAPED_UNICODE)
            : (string) $entry;
        self::logger()->log($level, $entry, ['category' => $category] + $context);
    }

    /**
     * Replaces the logger for this process. Call it first in app.php, or in an
     * entry point, so no line reaches the default one.
     */
    public static function setLogger(Logger $logger): void {
        self::$logger = $logger;
    }

    /**
     * The logger log() writes through: a FileLogger until setLogger() replaces
     * it. The Container injects this same instance wherever a Logger is
     * type-hinted.
     */
    public static function logger(): Logger {
        return self::$logger ??= new FileLogger();
    }

    /**
     * The lowest LogLevel the built-in loggers write, e.g. LogLevel::WARNING
     * in production. Until set: debug in debug mode, info otherwise.
     */
    public static function setMinimumLevel(string $level): void {
        self::severity($level);
        self::$minimumLevel = $level;
    }

    /** Whether an entry at $level passes the minimum level — check it before building an expensive entry. */
    public static function isLogged(string $level): bool {
        return self::severity($level) >= self::severity(self::$minimumLevel ?? (self::$debug ? LogLevel::DEBUG : LogLevel::INFO));
    }

    private static function severity(string $level): int {
        return LogLevel::SEVERITY[$level] ?? throw new \InvalidArgumentException("Unknown log level '$level'; use a LogLevel constant.");
    }

    /**
     * Turns debug mode on or off.
     * @param boolean $debug
     */
    public static function setDebug($debug = true) {
        self::$debug = $debug;
    }
    /**
     * Whether debug mode is on.
     * @return boolean
     */
    public static function isDebug() {
        return self::$debug;
    }

    /**
     * Shutdown handler (registered by init()) for otherwise-unhandled fatal
     * errors. Logs the error, echoes it too when debug mode is on, emails it
     * to Config::ERROR_REPORT_EMAIL if that's set, and sends a 500. Only fatal
     * types count: error_get_last() also holds a mere warning, even an
     * @-suppressed one (@filemtime), which must not turn into a 500.
     */
    public static function errorHandler() {
        $error = error_get_last();
        if ($error && $error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR)) {
            $errstr = $error['message'] . ' @ ' . $error['file'] . ':' . $error['line'];
            if (self::isDebug()) {
                echo $errstr;
            }
            self::log($errstr, LogLevel::CRITICAL, 'PHP');
            if (defined('Config::ERROR_REPORT_EMAIL') && \Config::ERROR_REPORT_EMAIL) {
                Mail::send((string) \Config::ERROR_REPORT_EMAIL, \Config::PRODUCT . ' php error occurred', json_encode($error, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
            http_response_code(500);
        }
    }

}
