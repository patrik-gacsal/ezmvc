<?php
namespace EzMvc;

/**
 * A View that holds exactly one nested View (or plain string) as its content.
 * Subclasses call parent::__construct($child) and typically add their own
 * additional constructor parameters (e.g. styling options) before setTemplate().
 * Example: PaddingView.
 *
 * Only this class declares renderChild() — MultiChildView doesn't have it, so
 * calling it on the wrong widget shape is a plain "call to undefined method"
 * rather than something that needs a runtime guard.
 */
abstract class SingleChildView extends View {

    public function __construct(
        public View | string $child,
        ?string $id = null,
    ) {
        parent::__construct($id);
    }

    /**
     * Renders this widget's own $child property.
     * @return string
     */
    protected function renderChild(): string {
        return $this->renderNode($this->child);
    }

}
