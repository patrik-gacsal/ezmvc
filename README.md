# ezmvc

A lightweight PHP 8 MVC and REST API framework. No external dependencies — all core classes are hand-rolled.

> **Pre-release.** ezmvc is at 0.x: the API may change between minor versions until 1.0.0. Example views (`ColumnView`, `PaddingView`, `TextView`) are planned to move out of the core into a separate addon package.

## Requirements

- PHP 8.0+
- Apache 2.4+ with `mod_rewrite` (the `.htaccess` uses 2.4-only `[END]` and `Require` syntax)
- `mysqli` extension with `mysqlnd` driver

## Installation

### As a Composer dependency

```bash
composer require ezmvc/ezmvc
```

Then install the CLI tool globally to scaffold your project:

```bash
composer global require ezmvc/cli
cd your-project-root
ezmvc setup
```

### Manual / standalone

Clone or download this repository into your web root, then:

1. Copy `Config.php.sample` to `Config.php` and fill in your production values.
2. For local development, copy `Config.php.sample` to `Config.local.php` instead — it takes precedence over `Config.php` when present. Both files are gitignored and excluded from deployment.
3. Run `composer install` (only needed for `vendor/autoload.php` in `index.php`; the framework itself has no Composer deps).

## Deployment

The CLI tool includes an FTPS deploy command that uploads your project to a shared host safely:

> **Experimental.** Deployment to a local path is tested; deployment over FTPS, including `--zip`, has not yet been verified against a real host. Try it on a throwaway target with `--dry-run` first.

```bash
ezmvc deploy                   # upload all files
ezmvc deploy --changed         # only files modified since the last successful deploy
ezmvc deploy --dry-run         # preview without uploading
ezmvc deploy --full            # upload vendor/ even when composer.lock is unchanged
ezmvc deploy --no-maintenance  # skip the 503 window (see below)
ezmvc deploy --zip             # one archive, unpacked server-side (https only)
```

**This command exists because the target hosts only offer FTP.** If your server gives you SSH or git, use a real deployment tool — [Deployer](https://deployer.org/), `rsync`, or a `git pull` on the server. Those build a new release directory and switch a symlink, so the changeover is genuinely atomic and a failed deploy rolls back. FTP cannot do that: files land one at a time, in place, on the live site. Everything below is about making that as safe as it can be, not about matching what SSH-based tooling gives you for free.

Copy `deploy.json.sample` to `deploy.json` (gitignored) and configure your target.

**FTPS (shared hosting):**
```json
{
    "host":    "ftp.example.com",
    "port":    21,
    "ssl":     true,
    "user":    "ftpuser",
    "pass":    "secret",
    "remote":  "/public_html/",
    "base_url": "https://example.com"
}
```

**Local folder (local dev server such as XAMPP):**
```json
{
    "path":     "C:/xampp/htdocs/myproject/",
    "base_url": "http://localhost/myproject"
}
```

When `path` is set the tool copies files directly using the filesystem. `base_url` is optional in local mode — if provided the PHP version probe runs the same way as in FTPS mode.

### Maintenance window

Uploading takes as long as it takes, and for that whole stretch the server holds a mix of old and new files — new `core/` against old `views/`, or the reverse. To close that window, the deploy replaces `index.php` with a small 503 stub *first*, uploads everything else, and puts the real `index.php` back *last*. Since every non-public path routes through `index.php`, that single file gates the whole app: visitors get a clean "temporarily unavailable" page instead of a half-updated site, and the app comes back in one step at the end.

A marker file (`_ezmvc_maintenance`) sits on the server for the duration, so if a deploy dies mid-run the next one notices, tells you, and restores `index.php` even if it wasn't in the changed set. If the restore itself fails, the CLI says so loudly — re-run `ezmvc deploy` to finish.

Pass `--no-maintenance` to upload in place without the stub. The window is also skipped automatically if `index.php` is in your `exclude` list, since nothing would ever restore it.

### Archive upload (`--zip`)

Uploading thousands of files one FTP round trip at a time is the slow part of any deploy with real dependencies. `--zip` packs everything into a single archive, uploads that, and has the server unpack it.

The thing doing the unpacking is the maintenance stub itself. That is deliberate: `.htaccess` rewrites every non-public path to `index.php`, so a separate extractor script uploaded next to it would never execute — it would have to live in `public/`, which is the worst possible home for a script that writes files. The stub is already reachable, already temporary, and already replaced by the real `index.php` at the end of the run, so the endpoint's lifetime is bounded by construction rather than by remembering to clean it up.

How it is guarded:

- **Authorization.** Each run generates a fresh 128-bit token; only its SHA-256 is written into the stub, and it is compared with `hash_equals()`. The token travels in a request header, never in the URL, since query strings land in access logs.
- **HTTPS required.** `--zip` refuses a non-`https` `base_url` outright — otherwise the token and the archive would cross the network in the clear.
- **Expiry.** The stub carries a hard deadline (30 minutes). After it passes, the file is inert: a plain 503 page that cannot be commanded at all, so even a deploy that dies mid-run degrades to a harmless maintenance page rather than leaving a live write endpoint on the site.
- **Anything unauthorized gets the 503 maintenance page** — not a 401, not a 403, nothing that distinguishes the endpoint from an ordinary site being updated.
- **Archive validation.** Every entry is checked before a single byte is written: no absolute paths, no `..`, no backslashes, no drive letters, and no `index.php` (which would overwrite the running stub and kill the deploy halfway). One bad entry rejects the whole archive.
- **The payload never lingers.** The server deletes it after a successful unpack, the CLI deletes it again, and any archive left by a crashed run is swept at the start of the next deploy.

`--zip` needs the `zip` extension on both ends; if the server lacks it, the deploy says so and falls back to file-by-file automatically. It is opt-in — plain per-file upload remains the default.

### Composer dependencies over FTP

`vendor/` is usually the bulk of an upload — one round trip per file, and a real dependency tree runs to thousands of them. Its contents are decided entirely by `composer.lock`, so the deploy hashes that file and skips `vendor/` when the hash matches the last successful deploy. Per-file timestamps are *not* used for this: every `composer install` rewrites the whole tree, which would re-upload thousands of byte-identical files on the next `--changed` run. Use `--full` to force it.

Two things that keep this manageable:

```bash
composer install --no-dev --optimize-autoloader   # before deploying
```

`--no-dev` leaves out test frameworks and tooling, which are usually most of the file count, and `--optimize-autoloader` builds a classmap so the app doesn't stat its way through directories at runtime. The framework itself has no Composer dependencies at all, so a project that doesn't add any has effectively no `vendor/` to upload.

### What is uploaded

`deploy.json`, `Config.local.php`, and `logs/` file contents are always excluded. The `logs/` directory itself is created on the target if it does not exist. `.htaccess` files and the `private/` directory are always included. `Config.php` is in the default exclude list in `deploy.json.sample` — remove it from `exclude` when deploying to a local server where the config should be copied alongside the code. When `base_url` is set, the tool probes the server's PHP version, compares it against the local PHP running the CLI, aborts if the server is older (restoring `index.php` first), and warns on missing extensions (`mysqli`, `mysqlnd`, `openssl`). The probe is an authorized request to the maintenance stub, not a separate file — a standalone probe script would be rewritten to `index.php` by the catch-all and never execute. Leftover probe files and deploy archives from any previous interrupted deploy are cleaned up automatically at the start of each run.

`--changed` compares file timestamps against the last **successful** deploy. If any file fails to upload, the deploy state is deliberately not recorded and the command exits non-zero — so the next `--changed` run retries everything from that attempt rather than treating the files that never landed as delivered.

## Configuration

All configuration lives in `Config.php` as class constants. Never commit this file with real credentials.

```php
class Config {
    const DB_HOST    = 'localhost';
    const DB_NAME    = 'my_database';
    const DB_USER    = 'db_user';
    const DB_PASS    = 'db_password';
    const BASE_PATH  = 'http://localhost/my-project/';
    const LOGFILE_PREFIX = '/var/log/my-project/log';
    const API_KEY    = 'change-me';
}
```

## Request lifecycle

```
HTTP request
  → Apache .htaccess forwards the raw request path to index.php (no query-param rewriting)
  → index.php bootstraps core classes, calls Core::init(), loads app.php
  → app.php wires up DB, auth defaults, mail, controller defaults
  → index.php strips Config::BASE_PATH off the path, matches it against generated/routes.php
    (built by `ezmvc generate` from #[Get]/#[Post]/#[Web]/etc. attributes)
  → index.php dispatches to the matched Controller (HTML) or ApiController (REST) method
```

## API controllers

Extend `ApiController` and use PHP 8 route attributes:

```php
use EzMvc\ApiController;
use EzMvc\Attr\Get;
use EzMvc\Attr\Post;

#[ApiTag('users', 'User management')]
#[ApiAuth(ApiAuth::JWT)]
class UserController extends ApiController {
    public function init(): void {}

    #[Get('/users')]
    public function getAll(): Response {
        return Response::ok([]);
    }

    #[Post('/users')]
    #[ApiRequest(CreateUserRequest::class)]
    #[ApiResponse(UserResponse::class, 201)]
    public function create(): Response {
        $body = $this->getRequest();
        // ...
        return Response::created($result);
    }
}
```

An action **returns** a success or **throws** an error — it never `die()`s, so it stays a pure, testable function. Success: `Response::ok($data)` / `created($data, $location)` / `accepted($data)` / `noContent()` (or return a bare DTO → 200). Error: `throw ApiException::notFound('msg')` / `badRequest('msg')` / `unauthorized()` / `conflict()` / … (or `new ApiException($status, $msg)`, or a project subclass). The dispatcher renders either — errors as a uniform `ErrorResponse`; an unexpected exception becomes a generic 500.

After every route change, regenerate routes and Swagger spec:

```bash
ezmvc generate
```

## Page controllers (HTML)

Extend `PageController` and route actions with `#[Web('/path')]` (registers both GET and POST, matching how a page serves a form and processes it at the same URL):

```php
use EzMvc\Attr\Web;
use EzMvc\PageController;

class HomeController extends PageController {
    public function init(): void {}

    #[Web('/home')]
    public function defaultAction() {
        echo 'Hello world';
    }

    #[Web('/home/{id}')]
    public function show(): void {
        $id = $this->routeParam('id');
        // ...
    }
}
```

### Styling

Every View renders its own slugified class name on its root element (`PaddingView` → `.padding-view`, via `View::cssClass()`), and every page gets `controller-<slug> action-<slug>` on `<body>`. `ezmvc new view` writes a `templates/<slug>.css` beside each template with an empty rule already nested under that class — put the widget's styles inside it, using native CSS nesting:

```css
.padding-view {
    &.p-sm { padding: .5rem; }
    &.p-md { padding: 1rem; }
}
```

`ezmvc generate` concatenates every `templates/**.css` into `generated/app.css` (installed addons first, the project's own `templates/` last, so project rules win at equal specificity), and a page template links it with one `<?= $this->stylesheetTag() ?>` — `PageView` owns the path and the mtime cache-bust, so no template hard-codes either. It sits in `generated/` because it is a build artifact — gitignored, rebuilt every run, and whitelisted in `.htaccess` by exact name like `generated/swagger.json`. Rules are copied verbatim; only comments are stripped, so nothing served to a browser describes your directory layout or the framework (a `/*!` licence header is kept). The wrapper class is what keeps one widget's rules out of another's, so keep your rules inside it. That is collision avoidance, not encapsulation: a rule on a parent still inherits into nested widgets.

## ORM

```php
use EzMvc\Entity;
use EzMvc\SearchCriteria;

class User extends Entity {
    public $id, $name, $email, $created_at;

    protected function init() {
        $this->setTableName('users');
    }
}

// Bind once in app.php
Entity::bindDefaultConnection($db->getConnection());

// CRUD
$user = (new User())->get(1);
$user->name = 'Alice';
$user->update();

$users = (new User())->find(
    SearchCriteria::where(Op::eq('active', 1))->orderBy('name')->limit(20)
);
```

Generate entity classes from an existing database:

```bash
ezmvc scaffold                # every table in the project's configured DB
ezmvc scaffold --table=users  # a specific table
```

## Services

Business logic that spans entities or triggers side effects goes in a plain class under `services/`: no base class, and usable from page and API controllers alike. There are two ways to write one:

- **Pragmatic (the default).** The service works with `Entity` objects, calls `Mail`/`HttpClient` directly and throws `ApiException` for expected failures. It's simple and right for most small projects, but it is layered MVC, not Clean Architecture: the service depends on the ORM and, through it, on `mysqli`.
- **Clean (opt-in, per service).** The service works with plain domain classes and depends only on interfaces it owns (`OrderRepository`, `MailSender`, `PaymentGateway`), injected through the constructor. Adapters implement those interfaces with the ORM, `Mail` and `HttpClient`, and the controller maps HTTP in and out, including turning domain exceptions into HTTP errors.

The clean mode needs nothing from the framework beyond what it already has; `Container` wires each interface to its adapter:

```php
$container->addScoped(OrderRepository::class, SqlOrderRepository::class);
```

Both modes can coexist in one project. CLAUDE.md has the full rules and the file layout.

## Authentication (API)

Set in `app.php`:

```php
ApiController::setDefaultAuthType(ApiController::AUTH_JWT);
ApiController::setDefaultSecret(Config::API_KEY);
```

Override per controller with `$this->setAuthType(...)` in `init()`. `#[ApiAuth]` only documents the scheme in Swagger — it does not enforce anything, so keep it in step with `setAuthType()`. Available types:

| Constant | Mechanism |
|---|---|
| `AUTH_NONE` | No auth |
| `AUTH_APIKEY` | `APIKEY` header matches the secret (`Config::API_KEY` above) |
| `AUTH_APIKEY_ALT` | SHA-256 of the `APIKEY` header matches the secret, so only the hash is stored server-side |
| `AUTH_JWT` | `Authorization: Bearer <token>`, checked for expiry — HS256 by default, RS256 via `setDefaultJwtAlgorithm(Jwt::ALG_RS256)` |
| `AUTH_JWT_NOEXP` | Same but expiry not checked |

## Utility classes

| Class | Purpose |
|---|---|
| `Core` | Debug mode, logging facade (`log`, `setLogger`, `setMinimumLevel`), error handling, redirect |
| `Logger` | PSR-3-shaped logging interface — `FileLogger` (default), `StreamLogger`, or any PSR-3 logger |
| `Session` | Secure session with timeout and fingerprint validation |
| `Jwt` | JWT create / parse — HS256 and RS256 (RS256 needs `openssl`) |
| `HttpClient` | cURL wrapper for outgoing HTTP |
| `Validators\*` | `Stringable` regex-pattern builders (`new Validators\Email(optional: true)`) — see CLAUDE.md |
| `SearchCriteria` | Fluent WHERE clause builder |
| `Calendar` | Date/time helpers |
| `Mail` | Email sending — `PhpMail` (built-in `mail()`) by default; `Mail::setSender()` swaps in `new Smtp(...)`, `new SendGrid(...)`, or your own `MailSender` implementation. A `MailSender` type-hint receives the same instance |
| `Random` | Cryptographically secure random strings, hex, and ints (CSPRNG — `random_int`/`random_bytes`) |

## Swagger / API docs

After running `ezmvc generate`, the Swagger UI is available at `/swagger/` and the spec at `generated/swagger.json`.

The Swagger UI front-end assets aren't committed — they're the only third-party code in the repo. Out of the box the docs page loads them from a pinned CDN, so a fresh clone just works. To self-host them instead (no runtime CDN dependency — they then ship with a deploy), run `ezmvc swagger:mirror`, which downloads the pinned release into `swagger/`. The pinned version lives in `swagger/swagger-ui.version`; bump it and re-run to upgrade. The CDN copy is only loaded with the Subresource Integrity hashes in `swagger/swagger-ui.integrity.json`, which `swagger:mirror` rewrites — commit it together with the version file.

## License

MIT
