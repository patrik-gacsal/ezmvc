<?php
namespace EzMvc;

/**
 * A View that lays out an arbitrary number of nested Views (or plain strings).
 * Subclasses call parent::__construct($children) and typically add their own
 * additional constructor parameters before setTemplate().
 * Example: ColumnView.
 *
 * Only this class declares renderChildren() — SingleChildView doesn't have it,
 * so calling it on the wrong widget shape is a plain "call to undefined
 * method" rather than something that needs a runtime guard.
 */
abstract class MultiChildView extends View {

    public function __construct(
        public array $children = [],
        ?string $id = null,
    ) {
        parent::__construct($id);
    }

    /**
     * Renders this widget's own $children property, in order.
     * @return string
     */
    protected function renderChildren(): string {
        return implode('', array_map(fn($child) => $this->renderNode($child), $this->children));
    }

}
