<?php
    /* @var $this EzMvc\WelcomeView */
    /** @var EzMvc\WelcomeView $this */
    $esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <title>ezmvc</title>
        <style>
            :root { color-scheme: light dark; --fg:#1c1c1c; --muted:#6b6b6b; --bg:#fbfbfa; --card:#fff; --line:#e6e4e0; --ok:#2f7a4f; --bad:#b04a3a; --accent:#8a5a2b; }
            @media (prefers-color-scheme: dark) {
                :root { --fg:#e8e6e3; --muted:#9a9691; --bg:#1a1a19; --card:#232322; --line:#34332f; --ok:#6cbf8b; --bad:#e08a78; --accent:#d0a068; }
            }
            body { margin:0; background:var(--bg); color:var(--fg); font:15px/1.65 system-ui,-apple-system,"Segoe UI",sans-serif; }
            main { max-width:44rem; margin:0 auto; padding:4rem 1.5rem 5rem; }
            h1 { margin:0; font-size:2rem; letter-spacing:-.02em; }
            .lead { margin:.4rem 0 2.5rem; color:var(--muted); }
            h2 { margin:2.5rem 0 .75rem; font-size:.8rem; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); font-weight:600; }
            ol { margin:0; padding:0; list-style:none; counter-reset:step; }
            ol li { counter-increment:step; padding:.7rem 0 .7rem 2.2rem; border-bottom:1px solid var(--line); position:relative; }
            ol li:last-child { border-bottom:0; }
            ol li::before { content:counter(step); position:absolute; left:0; top:.75rem; width:1.5rem; height:1.5rem; border-radius:50%; background:var(--card); border:1px solid var(--line); color:var(--muted); font-size:.75rem; line-height:1.45rem; text-align:center; }
            .checks { margin:0; padding:0; list-style:none; background:var(--card); border:1px solid var(--line); border-radius:.5rem; }
            .checks li { display:flex; gap:.7rem; align-items:baseline; padding:.65rem 1rem; border-bottom:1px solid var(--line); }
            .checks li:last-child { border-bottom:0; }
            .mark { font-weight:700; }
            .mark.ok { color:var(--ok); }
            .mark.bad { color:var(--bad); }
            .detail { margin-left:auto; color:var(--muted); font-size:.85rem; text-align:right; }
            code { font-family:ui-monospace,Consolas,Menlo,monospace; font-size:.87em; background:var(--card); border:1px solid var(--line); border-radius:.25rem; padding:.1rem .35rem; }
            a { color:var(--accent); }
            footer { margin-top:3rem; padding-top:1.25rem; border-top:1px solid var(--line); color:var(--muted); font-size:.85rem; }
        </style>
    </head>
    <body<?= $this->idAttr() ?> class="<?= $this->cssClass() ?>">
        <main>
            <h1>ezmvc is running.</h1>
            <p class="lead">You are seeing this because no default page controller is configured yet. This page disappears the moment you wire up your own.</p>

            <h2>Environment</h2>
            <ul class="checks">
                <?php foreach ($this->checks as $check): ?>
                    <li>
                        <span class="mark <?= $check['ok'] ? 'ok' : 'bad' ?>"><?= $check['ok'] ? '&checkmark;' : '&times;' ?></span>
                        <span><?= $esc($check['label']) ?></span>
                        <span class="detail"><?= $esc($check['detail']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <h2>Next steps</h2>
            <ol>
                <li>Fill in <code>Config.php</code> — database credentials, <code>BASE_PATH</code>, <code>API_KEY</code>.</li>
                <li>Write a controller in <code>controllers/</code> and route it with <code>#[Web('/')]</code>.</li>
                <li>Point the fallback at it in <code>app.php</code>: <code>PageController::setDefaultController('MyController', 'defaultPage');</code></li>
                <li>Run <code>ezmvc generate</code> after every routing change.</li>
                <li>Have a database already? <code>ezmvc scaffold</code> writes the entities and DTOs for you.</li>
            </ol>

            <footer>
                API docs are at <a href="swagger/">/swagger/</a>. This page lives in <code>core/welcome/</code> — delete the folder when you no longer want it.
            </footer>
        </main>
    </body>
</html>
