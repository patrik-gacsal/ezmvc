<?php
    /* @var $this DefaultPageView*/
    /** @var DefaultPageView $this */
    $esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= $esc($this->lang) ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <title><?= $esc($this->title) ?></title>
        <?= $this->stylesheetTag() ?>
    </head>
    <body class="<?= $esc(implode(' ', array_merge([$this->cssClass()], $this->bodyClasses))) ?>">
        <div class="container">
            <?= $this->renderChild() ?>
        </div>
    </body>
</html>
