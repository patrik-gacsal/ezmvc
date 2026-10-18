<?php
namespace EzMvc;

/**
 * The built-in start page, shown at the site root when a project has no
 * default page controller of its own configured yet. Gathers the environment
 * facts a first run needs to check and hands them to WelcomeView to render.
 *
 * Replace it by wiring your own in app.php:
 *   PageController::setDefaultController('MyController', 'defaultPage');
 */
class WelcomeController extends PageController {

    public function init(): void {}

    /** The start page with the environment checks; index.php renders it at the root. */
    public function defaultPage(): WelcomeView {
        return new WelcomeView($this->environmentChecks());
    }

    /**
     * @return array<int, array{label: string, ok: bool, detail: string}>
     */
    private function environmentChecks(): array {
        $mysqlnd = extension_loaded('mysqli') && function_exists('mysqli_stmt_get_result');

        return [
            [
                'label'  => 'PHP 8.0 or newer',
                'ok'     => PHP_VERSION_ID >= 80000,
                'detail' => PHP_VERSION,
            ],
            [
                'label'  => 'mysqli with the mysqlnd driver',
                'ok'     => $mysqlnd,
                'detail' => $mysqlnd ? 'loaded' : 'Entity::get() and Database::fetchAll() need get_result()',
            ],
            [
                'label'  => 'Routes generated',
                'ok'     => file_exists('generated/routes.php'),
                'detail' => file_exists('generated/routes.php') ? 'generated/routes.php' : 'run: ezmvc generate',
            ],
            [
                'label'  => 'Composer autoloader',
                'ok'     => file_exists('vendor/autoload.php'),
                'detail' => file_exists('vendor/autoload.php') ? 'vendor/autoload.php' : 'optional — manual require_once works too',
            ],
        ];
    }

}
