<?php
define('EZMVC', true);

include_once file_exists('Config.local.php') ? 'Config.local.php' : 'Config.php';
include_once 'core/Session.php';
include_once 'core/validators/Validator.php';
include_once 'core/validators/LengthValidator.php';
foreach (glob('core/validators/*.php') as $validatorFile) {
    include_once $validatorFile;
}
include_once 'core/mail/MailAddress.php';
include_once 'core/mail/MailAttachment.php';
include_once 'core/mail/MimeBuilder.php';
include_once 'core/mail/MailSender.php';
include_once 'core/mail/PhpMail.php';
include_once 'core/mail/Mail.php';
include_once 'core/orm/Database.php';
include_once 'core/orm/SearchCriteria.php';
include_once 'core/orm/EntityMeta.php';
include_once 'core/orm/Entity.php';
include_once 'core/Random.php';
include_once 'core/Calendar.php';
include_once 'core/view/View.php';
include_once 'core/view/SingleChildView.php';
include_once 'core/view/MultiChildView.php';
include_once 'core/view/PageView.php';
include_once 'core/Controller.php';
include_once 'core/PageController.php';
include_once 'core/ApiController.php';
include_once 'core/Jwt.php';
include_once 'core/ErrorResponse.php';
include_once 'core/exceptions/ApiException.php';
include_once 'core/Response.php';
include_once 'core/SchemaBinder.php';
include_once 'core/Request.php';
include_once 'core/Core.php';
include_once 'core/logging/LogLevel.php';
include_once 'core/logging/Logger.php';
include_once 'core/logging/LineLogger.php';
include_once 'core/logging/FileLogger.php';
include_once 'core/logging/StreamLogger.php';
include_once 'core/Container.php';
include_once 'core/Router.php';
include_once 'core/attributes/Route.php';
include_once 'core/attributes/Entity.php';
include_once 'core/attributes/Validation.php';
require_once 'core/HttpClient.php';
if (file_exists('core/attributes/Swagger.php')) {
    include_once 'core/attributes/Swagger.php';
}

if (file_exists('vendor/autoload.php')) {
    require_once 'vendor/autoload.php';
}

use EzMvc\ApiController;
use EzMvc\ApiException;
use EzMvc\Container;
use EzMvc\Response;
use EzMvc\Core;
use EzMvc\ErrorResponse;
use EzMvc\LogLevel;
use EzMvc\PageController;
use EzMvc\Router;
use EzMvc\Session;

Core::init();
// Defaults for every response: no MIME sniffing, no framing by other sites.
// app.php or an action overrides them with header() / header_remove().
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");
$requestMethod = $_SERVER['REQUEST_METHOD'];
if ($requestMethod !== 'OPTIONS') {
    require_once 'app.php';
    Core::logRequest();   // after app.php, so the REQ line reaches the logger it chose
    if (Core::isLogged(LogLevel::DEBUG)) {
        $content = file_get_contents('php://input');
        if ($content !== '' && strlen($content) < 4096) {
            Core::log(Core::redactSecrets($content), LogLevel::DEBUG, 'REQ-BODY');
        }
    }
}

// In debug, surface everything (warnings/notices included) so bugs are visible;
// in production, suppress and never display — the dispatch handlers below render
// a clean 500 and log the detail instead.
if (Core::isDebug()) {
    error_reporting(E_ALL);
    ini_set('display_errors', 'On');
} else {
    error_reporting(0);
    ini_set('display_errors', 'Off');
}

// Attribute-based routing
$basePath   = trim(parse_url(\Config::BASE_PATH, PHP_URL_PATH), '/');
$requestUri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$routeUri   = substr($requestUri, strlen($basePath)) ?: '';

if ($requestMethod === 'OPTIONS') {
    apiHeaders();
    die();
}

$route = Router::match($requestMethod, trim($routeUri, '/'));
if ($route) {
    require_once 'controllers/' . ($route['file'] ?? $route['controller'] . '.php');
    $routedController = Container::root()->create($route['controller']);
    $routedController->init();
    $routedController->setRouteParams(Router::getParams());
    $routedAction = $route['method'];

    if ($routedController instanceof ApiController) {
        apiHeaders();
        try {
            $routedController->setRequest(json_decode(file_get_contents('php://input')));
            $routedController->authenticate();
            // the validated #[ApiRequest] body, for an action that declares it as a parameter
            $actionArguments = $routedController->performRequestCheck($routedAction);
            $response        = $routedController->$routedAction(...$actionArguments);
            // an action may return a Response, or a bare DTO/object (→ 200),
            // or nothing (→ 204)
            if (! $response instanceof Response) {
                $response = $response === null ? Response::noContent() : Response::ok($response);
            }
            // a successful response honors the controller's cache setting (default
            // from app.php, or per-controller from init()); an action can override
            // by setting the header on its own Response
            $response->headers['Cache-Control'] ??= $routedController->getCacheControl();
        } catch (ApiException $e) {
            // deliberate, expected error: its own status + message go to the client
            $response = new Response($e->status, new ErrorResponse($e));
        } catch (\Throwable $e) {
            // unexpected: logged in full, a generic message in production so nothing internal leaks
            Core::log(get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), LogLevel::ERROR, 'Dispatch');
            $response = new Response(500, new ErrorResponse(Core::isDebug() ? $e : 'Internal server error'));
        }
        $response->headers['Cache-Control'] ??= 'no-cache';   // errors are never cached
        $response->send();
    }

    if ($routedController instanceof PageController) {
        Session::init(Config::SESSION_NAME);
        try {
            echo $routedController->renderAction($routedAction, $route['ajax']);
        } catch (\Throwable $e) {
            errorScreen($e);
        }
        die();
    }
}

try {
    $defaultContent = PageController::getDefaultControllerActionContent();
} catch (\Throwable $e) {
    errorScreen($e);
    die();
}
if ($defaultContent) {
    echo $defaultContent;
} elseif (trim($routeUri, '/') === '' && defined('Config::ENABLE_WELCOME_PAGE') && Config::ENABLE_WELCOME_PAGE) {
    // Nothing routed and no default page controller configured: the project
    // isn't wired up yet, so the built-in start page stands in. Root only —
    // every other unknown path stays an ordinary 404. It shows the PHP version,
    // so it is off unless Config enables it.
    require_once 'core/welcome/WelcomeView.php';
    require_once 'core/welcome/WelcomeController.php';
    $welcome = new \EzMvc\WelcomeController();
    $welcome->init();
    echo $welcome->defaultPage()->toString();
} else {
    http_response_code(404);
}

/**
 * Renders a 500 and logs the error. In debug mode prints a formatted HTML
 * screen with the exception class, message, file:line and stack trace; in
 * production a generic message with no internals leaked.
 */
function errorScreen(\Throwable $e): void {
    http_response_code(500);
    Core::log($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), LogLevel::ERROR, 'Dispatch');

    if (! Core::isDebug()) {
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<title>500 Internal Server Error</title></head><body>'
            . '<h1>500 Internal Server Error</h1>'
            . '<p>Something went wrong. Please try again later.</p>'
            . '</body></html>';
        return;
    }

    $esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<title>' . $esc(get_class($e)) . '</title><style>'
        . 'body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;margin:0;background:#1e1e1e;color:#e0e0e0}'
        . '.hdr{background:#c0392b;color:#fff;padding:20px 28px}'
        . '.hdr h1{margin:0;font-size:18px;font-weight:600}'
        . '.hdr .msg{margin-top:8px;font-size:15px;font-family:Consolas,Menlo,monospace;word-break:break-word}'
        . '.loc{padding:14px 28px;background:#2d2d2d;font-family:Consolas,Menlo,monospace;font-size:13px;color:#9cdcfe;border-bottom:1px solid #3c3c3c}'
        . 'pre{margin:0;padding:20px 28px;font-family:Consolas,Menlo,monospace;font-size:13px;line-height:1.6;white-space:pre-wrap;word-break:break-word}'
        . '</style></head><body>'
        . '<div class="hdr"><h1>' . $esc(get_class($e)) . '</h1>'
        . '<div class="msg">' . $esc($e->getMessage()) . '</div></div>'
        . '<div class="loc">' . $esc($e->getFile()) . ' : ' . (int) $e->getLine() . '</div>'
        . '<pre>' . $esc($e->getTraceAsString()) . '</pre>'
        . '</body></html>';
}

function apiHeaders(): void {
    $origin       = $_SERVER['HTTP_ORIGIN'] ?? null;
    $originHost   = $origin ? parse_url($origin, PHP_URL_HOST) : null;
    $enabledHosts = Config::ENABLED_CLIENT_HOSTS;

    if ($origin && $originHost && in_array($originHost, $enabledHosts, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    }
    // the CORS headers above differ by Origin, so a cache must not serve one origin's copy to another
    header('Vary: Origin');

    header('Access-Control-Allow-Methods: ' . implode(', ', ApiController::getEnabledMethods()));
    header('Access-Control-Allow-Headers: APIKEY, Authorization, content-type');
    header('Cache-Control: no-cache');
}
