<?php

use EzMvc\MultiChildView;

/**
 * Lays out an arbitrary number of children, one after another.
 */
class ColumnView extends MultiChildView {

    public function __construct(array $children = [], ?string $id = null) {
        parent::__construct($children, $id);
        $this->setTemplate('components/column');
    }

}
