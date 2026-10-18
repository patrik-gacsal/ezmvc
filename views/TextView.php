<?php

use EzMvc\View;

/**
 * A leaf node: just renders its own text, no children of any kind.
 */
class TextView extends View {

    public function __construct(
        public string $text,
        ?string $id = null,
    ) {
        parent::__construct($id);
        $this->setTemplate('components/text');
    }

}
