<?php
namespace EzMvc;

/**
 * Wraps a full HTML page around the captured output of a PageController action.
 * A SingleChildView: the wrapped content (the action's rendered output, or a
 * View passed directly) is its one child, rendered with renderChild().
 *
 * It takes only the primitives a page template needs — title, language and the
 * <body> CSS classes — never a PageController. When $bodyClasses is omitted it
 * defaults to the currently matched route's classes (Router::bodyClasses()).
 * Subclasses call setTemplate() in their constructor to select the page template.
 *
 * Typical template usage:
 *   <html lang="<?= $this->lang ?>"> ... <title><?= $this->title ?></title>
 *   <?= $this->stylesheetTag() ?>   — link the generated stylesheet
 *   <body class="<?= implode(' ', $this->bodyClasses) ?>">
 *   <?= $this->renderChild() ?>    — render the action output
 */
abstract class PageView extends SingleChildView {

    /** Built by `ezmvc generate` from every templates/**.css. */
    private const STYLESHEET_FILE = 'generated/app.css';

    public ?string $title;
    public ?string $lang;
    public array $bodyClasses;

    public function __construct(
        View | string $child,
        ?string $title = null,
        ?string $lang = null,
        ?array $bodyClasses = null,
        ?string $id = null,
    ) {
        parent::__construct($child, $id);
        $this->title       = $title;
        $this->lang        = $lang;
        $this->bodyClasses = $bodyClasses ?? Router::bodyClasses();
    }

    /**
     * Turns a project-root-relative path into a URL the browser can fetch.
     * Only the path component of Config::BASE_PATH is prefixed, never its host,
     * so a page served from a different hostname still links its own assets.
     * Use it for a project's hand-placed files too: assetUrl('public/site.css').
     * @param string $path
     * @return string
     */
    public function assetUrl(string $path): string {
        $base = rtrim(parse_url(\Config::BASE_PATH, PHP_URL_PATH) ?? '', '/');
        return $base . '/' . ltrim($path, '/');
    }

    /**
     * The <link> element for the generated stylesheet — escaped and ready to
     * echo in <head> — or '' when `ezmvc generate` has not built it yet, which
     * is the normal state of a fresh clone. Same contract as View::idAttr():
     * the template emits it without deciding anything.
     *
     * The href carries the file's modification time, so a deploy invalidates
     * the browser cache without anyone bumping a version by hand. That costs
     * one stat() per page render.
     * @return string
     */
    public function stylesheetTag(): string {
        $version = @filemtime(self::STYLESHEET_FILE);
        if (! $version) {
            return '';
        }
        $href = $this->assetUrl(self::STYLESHEET_FILE) . '?v=' . $version;
        return '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
    }

}
