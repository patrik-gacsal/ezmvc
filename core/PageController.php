<?php
namespace EzMvc;

/**
 * Base class for HTML page controllers. Route actions with #[Web('/path')]
 * (GET + POST) or #[Get], #[Post] individually. An action returns a View or
 * a string, or echoes; the content is wrapped in the configured PageView.
 */
abstract class PageController extends Controller {

    private static ?string $defaultPageView       = null;
    private static ?string $defaultPageController = null;
    private static ?string $defaultAction         = null;
    private ?string $pageView                     = null;
    private ?string $title                        = null;

    /** The page <title>, handed to the PageView. */
    protected function setTitle(string $title): void {
        $this->title = $title;
    }

    /** The title set with setTitle(), or null. */
    public function getTitle(): ?string {
        return $this->title;
    }

    /** PageView class for this controller, overriding setDefaultPageView(). */
    protected function setPageView(string $view): void {
        $this->pageView = $view;
    }

    /** PageView class wrapping every page controller's output; set in app.php. */
    public static function setDefaultPageView(string $pageView): void {
        self::$defaultPageView = $pageView;
    }

    /** Controller class and action that render when no route matches. */
    public static function setDefaultController(string $controller, string $action) {
        self::$defaultPageController = $controller;
        self::$defaultAction         = $action;
    }

    /** The default controller's rendered page, or null when none is set. */
    public static function getDefaultControllerActionContent(): ?string {
        if (! self::$defaultPageController || ! self::$defaultAction) {
            return null;
        }
        /* @var $controller PageController */
        $controller = Container::root()->create(self::$defaultPageController);
        $controller->init();
        return $controller->renderAction(
            self::$defaultAction,
            bodyClasses: Router::bodyClassesFor(self::$defaultPageController, self::$defaultAction),
        ) ?: null;
    }

    /**
     * Runs $action and returns its content — the View it returns, the string it
     * returns, or else whatever it echoed — wrapped in the PageView unless $ajax.
     * @param ?array $bodyClasses defaults to the matched route's (see PageView)
     * @throws \LogicException when the configured PageView class doesn't exist
     */
    public function renderAction(string $action, bool $ajax = false, ?array $bodyClasses = null): string {
        ob_start();
        try {
            $result = $this->$action();
        } finally {
            $content = ob_get_clean();
        }
        if ($result instanceof View) {
            $content = $result->toString();
        } elseif (is_string($result)) {
            $content = $result;
        }

        $pageView = $ajax ? null : $this->getPageView();
        if (! $pageView) {
            return $content;
        }
        if (! class_exists($pageView)) {
            throw new \LogicException('Invalid PageView: ' . $pageView);
        }
        return (new $pageView($content, $this->getTitle(), Session::get('lang'), $bodyClasses))->toString();
    }

    /** The PageView class in effect: this controller's, else the default. */
    public function getPageView(): ?string {
        return $this->pageView ?? self::$defaultPageView;
    }

    /** Whether this is a POST — how a #[Web] action tells form submission from display. */
    protected function isPost(): bool {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    }

    /** Whether this is a GET. */
    protected function isGet(): bool {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET';
    }

}
