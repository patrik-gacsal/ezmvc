<?php
    require_once '../Config.php';
    if (! Config::ENABLE_SWAGGER) {
        http_response_code(404);
        die();
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: frame-ancestors 'self'");

    // The Swagger UI assets are never committed (see .gitignore). Serve the locally
    // mirrored copy if it's present — run `ezmvc swagger:mirror` to fetch it, which is
    // what a real deployment ships — otherwise fall back to the pinned CDN, but only
    // with the Subresource Integrity hashes recorded for that exact version, so a
    // compromised CDN can't run script on this origin.
    $ver    = trim(@file_get_contents(__DIR__ . '/swagger-ui.version')) ?: '5';
    $local  = file_exists(__DIR__ . '/swagger-ui-bundle.js');
    $hashes = json_decode(@file_get_contents(__DIR__ . '/swagger-ui.integrity.json') ?: '', true) ?: [];
    if (! $local && ($hashes['version'] ?? null) !== $ver) {
        http_response_code(503);
        die('Swagger UI assets missing: run `ezmvc swagger:mirror`.');
    }
    $base = $local ? '.' : 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@' . rawurlencode($ver);
    $sri  = fn(string $asset) => $local
        ? ''
        : ' integrity="' . htmlspecialchars($hashes[$asset] ?? '', ENT_QUOTES, 'UTF-8') . '" crossorigin="anonymous"';
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars(Config::PRODUCT, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" type="text/css" href="<?php echo $base ?>/swagger-ui.css"<?php echo $sri('swagger-ui.css') ?> />
    <link rel="stylesheet" type="text/css" href="index.css" />
    <link rel="icon" type="image/png" href="<?php echo $base ?>/favicon-32x32.png" sizes="32x32" />
    <link rel="icon" type="image/png" href="<?php echo $base ?>/favicon-16x16.png" sizes="16x16" />
  </head>

  <body>
    <div id="swagger-ui"></div>
    <script src="<?php echo $base ?>/swagger-ui-bundle.js" charset="UTF-8"<?php echo $sri('swagger-ui-bundle.js') ?>></script>
    <script src="<?php echo $base ?>/swagger-ui-standalone-preset.js" charset="UTF-8"<?php echo $sri('swagger-ui-standalone-preset.js') ?>></script>
    <script src="./swagger-initializer.js" charset="UTF-8"></script>
  </body>
</html>
