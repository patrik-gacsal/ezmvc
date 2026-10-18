<?php
namespace EzMvc;

/**
 * Renders the built-in start page. A leaf View whose template is a complete
 * HTML document rather than a fragment, so the page still renders on a project
 * that has removed or not yet written its own PageView.
 *
 * The template can be overridden without touching core/ by placing a file at
 * templates/welcome/welcome.php — the project's own templates/ wins.
 */
class WelcomeView extends View {

    /**
     * @param array<int, array{label: string, ok: bool, detail: string}> $checks
     * @param string|null $id
     */
    public function __construct(
        public array $checks = [],
        ?string $id = null,
    ) {
        parent::__construct($id);
        $this->setTemplate('welcome/welcome');
    }

}
