<?php
namespace EzMvc;

/**
 * This class is the root of all ViewModel classes in the project.
 * The subclass must have a template and contain all attributes what the template needs.
 */
abstract class View {

    private $templateName     = 'Please call <b>setTemplate()</b> in the constructor of the view class.';
    private bool $isRendering = false;

    /** Template name → resolved file path, filled on first use. */
    private static array $templatePaths = [];

    /** An optional DOM id for this view's root element — see idAttr(). */
    public ?string $id = null;

    /**
     * Every view accepts an optional id. Concrete views add their own
     * constructor parameters and forward the id up via parent::__construct().
     * @param string|null $id
     */
    public function __construct(?string $id = null) {
        $this->id = $id;
    }

    /**
     * This view's own type as a slugified CSS class, e.g. TextView → "text-view"
     * (the class name is kept whole — the "View" suffix is not stripped). A
     * per-view styling hook for templates: class="<?= $this->cssClass() ?>".
     * @return string
     */
    public function cssClass(): string {
        return Core::slug((new \ReflectionClass(static::class))->getShortName());
    }

    /**
     * An escaped id attribute (` id="…"`) when this view has an id, or '' when
     * it doesn't — for a template's root element:
     * <span<?= $this->idAttr() ?> class="…">.
     * @return string
     */
    public function idAttr(): string {
        return $this->id !== null && $this->id !== ''
            ? ' id="' . htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8') . '"'
            : '';
    }

    /**
     * Sets View's template.
     * The template files should be in /templates folder.
     * Example:
     *  for calling template: /templates/example/view.php
     *  you should use: $this->setTemplate('example/view');
     * @param string $templateName
     */
    protected function setTemplate(string $templateName): void {
        $this->templateName = $templateName;
    }
    /** Prints toString(). */
    public function show(): void {
        echo $this->toString();
    }
    /**
     * Renders the template and returns the output.
     *
     * Never call this (or show()) on $this from within its own template: that
     * would include the template recursively, so a reentrant call throws. To
     * nest a View, render the CHILD instance.
     * @return string
     * @throws \LogicException if called again on this instance while it's
     *   already rendering.
     */
    public function toString(): string {
        $this->guardAgainstReentrantRender();
        $this->isRendering = true;
        try {
            ob_start();
            include $this->templatePath();
            return ob_get_clean();
        } finally {
            $this->isRendering = false;
        }
    }

    /**
     * Renders a nested View, or casts a string. The building block for
     * renderChild()/renderChildren() — a template calls those instead, since
     * they are bound to $this->child/$this->children and can't render the
     * wrong node.
     * @param View|string $node
     * @return string
     */
    protected function renderNode(View | string $node): string {
        return $node instanceof self ? $node->toString() : (string) $node;
    }

    /**
     * Resolves this view's template name to a file. The project's own
     * templates/ directory is tried first, so a project can override a
     * framework-supplied template (e.g. welcome/welcome) by dropping a
     * same-named file there, without editing anything under core/.
     *
     * The resolution is cached per template name, so a page built from many
     * instances of one widget costs a single filesystem probe rather than one
     * per instance.
     * @return string
     */
    private function templatePath(): string {
        if (! isset(self::$templatePaths[$this->templateName])) {
            $projectTemplate = 'templates/' . $this->templateName . '.php';
            self::$templatePaths[$this->templateName] = file_exists($projectTemplate)
                ? $projectTemplate
                : 'core/' . $this->templateName . '.php';
        }
        return self::$templatePaths[$this->templateName];
    }

    private function guardAgainstReentrantRender(): void {
        if ($this->isRendering) {
            throw new \LogicException(
                static::class . '::toString()/show() was called on itself while already rendering — '
                . 'this recurses infinitely. To render a nested View, call ->toString()/->show() on the '
                . 'CHILD instance, not on $this.'
            );
        }
    }

}
