<?php

use EzMvc\PageView;
use EzMvc\View;

class DefaultPageView extends PageView {

    public function __construct(
        View|string $child,
        ?string $title = null,
        ?string $lang = null,
        ?array $bodyClasses = null,
        ?string $id = null,
    ) {
        parent::__construct($child, $title, $lang, $bodyClasses, $id);
        $this->setTemplate('page');
    }

}
