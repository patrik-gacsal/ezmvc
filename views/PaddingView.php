<?php

use EzMvc\SingleChildView;
use EzMvc\View;

/**
 * Wraps exactly one nested View (or plain string) with spacing.
 */
class PaddingView extends SingleChildView {

    public function __construct(
        View|string $child,
        public string $size = 'md',
        ?string $id = null,
    ) {
        parent::__construct($child, $id);
        $this->setTemplate('components/padding');
    }

}
