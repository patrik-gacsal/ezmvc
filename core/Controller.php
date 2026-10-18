<?php
namespace EzMvc;

/**
 * Base class for all controllers. Provides route parameter access
 * and the init() contract shared by ApiController and PageController.
 */
abstract class Controller {

    private array $routeParams = [];

    /**
     * Per-request setup (auth type, page view, title…), called right after
     * construction and before the route params are set and the action runs.
     */
    abstract public function init(): void;

    /** Called by the dispatcher with the matched route's {name} => value pairs. */
    public function setRouteParams(array $params): void {
        $this->routeParams = $params;
    }

    /** The value of the route's {$name} placeholder, or null. */
    protected function routeParam(string $name): ?string {
        return $this->routeParams[$name] ?? null;
    }

    /**
     * @return string[]
     */
    protected function routeParams(): array {
        return $this->routeParams;
    }

}
