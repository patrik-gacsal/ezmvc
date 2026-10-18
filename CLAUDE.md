# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Is

ezmvc is a custom lightweight PHP MVC framework. There is no Laravel, Symfony, or other external framework — all core classes are hand-rolled in `core/`.

`core/`'s root holds the request pipeline — controllers, request/response, routing, session, JWT — and the cross-cutting classes (`Core`, `Container`, `HttpClient`, `Calendar`, `Random`). Self-contained features get a folder: `orm/` (Entity, SearchCriteria, Database, scaffolder), `view/`, `mail/`, `logging/`, plus `attributes/`, `validators/`, `exceptions/`, `swaggergen/` and `welcome/`. Folders organise files only: every class stays in the flat `EzMvc` namespace (`Validators\*` excepted), which classmap autoloading and the explicit includes in `index.php` both allow. `core/Core.php` is the path the CLI looks for to recognise a framework install, so it stays at the root.

## Setup

1. Install Composer dependencies: `composer install`
2. Copy `Config.php` and fill in real values for `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `API_KEY`, `BASE_PATH`, and `LOGFILE_PREFIX`.
3. Requires **Apache 2.4+** with `mod_rewrite` enabled (the `.htaccess` uses the 2.4-only `[END]` flag and `Require` syntax; `Order/Deny` is avoided, since on 2.4 without `mod_access_compat` it would 500). **Security model — whitelist + uniform 404:** the root `.htaccess` serves a small set of public paths directly (`public/`, `swagger/`, `generated/swagger.json`, `generated/app.css`, `favicon.ico` — the two `generated/` entries are whitelisted by exact name, so the rest of that directory, `routes.php` included, stays private — but no PHP runs from them: a `.php`/`.phtml`/`.phar` request under `public/` or `swagger/` other than `swagger/index.php`, including `x.php/extra` and `x.php.jpg`, gets the same uniform 404, so an uploaded script can't execute) and routes **everything else** through `index.php` via a catch-all (`RewriteRule ^ index.php [END]` — `[END]`, not `[L]`, is required: with no `!-f` guard, `[L]` would rewrite `index.php` to itself and loop to a 500). So a request for any non-public file (`core/`, `Config.php`, `app.php`, `deploy.json`, `.git/`, …) gets the **same 404 as a random unknown route** — never a `403`, which would confirm the file/dir exists (the code-dir names like `core/`/`entities/` *are* the framework fingerprint, so this matters).

   **Why almost no per-directory `.htaccess`:** Apache evaluates authorization (`Require`) *before* per-directory `mod_rewrite` (fixup phase), so a per-dir `Require all denied` **pre-empts the catch-all with a 403** — it can't be a silent fallback, it just reintroduces the fingerprint. So per-dir denies are used **only** for the two secret *data* dirs, `logs/` and `private/`, where the trade is worth it: their names are generic (not a framework tell), a stray `.log` served as plaintext would be a real leak, and their standalone `Require all denied` works via `mod_authz` **independently of `mod_rewrite`** — so it still protects even if the root `.htaccess` is mis-edited or `mod_rewrite` dies. They are deliberately *not* consolidated into the root file (that would couple the secrets to the file most likely to be fat-fingered). Code dirs get nothing — if the root routing ever breaks, they merely execute to a blank page, no leak. For anything truly sensitive, point `Config::LOGFILE_PREFIX` outside the web root so `logs/` isn't web-reachable at all.
4. Requires PHP with `mysqli` and the `mysqlnd` driver (used by `Entity::get()`'s `get_result()`).
5. (Optional, but recommended when exploring or extending the framework) Import `example.sql` into your database and run `ezmvc scaffold` — this gives you a working Entity/Dto pair to test against immediately, without first describing a real project's schema.

**Autoloading is optional.** `index.php` already checks for `vendor/autoload.php` and includes it if present, but nothing in the framework requires Composer's autoloader — manual `require_once` for core/entities/dtos/etc. always works too. If `composer.json` has an `autoload.classmap` section (created automatically by `ezmvc setup`, listing `core/`, `entities/`, `dtos/`, `requests/`, `responses/`, `services/`, `exceptions/`, `views/`, `controllers/`), `ezmvc generate` runs `composer dump-autoload` after building routes/swagger to keep it in sync with any new classes — classmap autoloading is a static snapshot, so it needs regenerating whenever a class file is added, and `generate` is the natural place for that since it's already the "run this after making changes" command. `exceptions/` is where a project's own custom exceptions belong (e.g. the `ValidationException` convention from the Services section below) — the framework doesn't ship any of its own there.

## Request Lifecycle

```
HTTP request
  → Apache .htaccess forwards the raw request path to index.php (no rewriting into query params)
  → index.php: bootstraps core classes, calls Core::init(), loads app.php
  → app.php: app-specific setup (DB connection, auth defaults, mail init, controller defaults)
  → index.php: strips Config::BASE_PATH off the request path, calls Router::match()
  → Router::match() checks the request path/method against generated/routes.php
    (built by `ezmvc generate` from #[Get]/#[Post]/#[Put]/#[Delete]/#[Patch]/#[Web]/#[Custom] attributes)
  → index.php: dispatches to the matched controller's method (Controller or ApiController)
```

There is no separate slug-based or query-string-based routing convention — attribute-based routing (`#[Get('/users/{id}')]` etc., see "Attribute-Based Routing and Swagger" below) is the only routing mechanism. A route's `{name}` placeholders become `$this->routeParam('name')` in the controller. Unmatched requests fall through to `PageController::getDefaultControllerActionContent()`, then a 404.

Only the whitelisted public paths bypass routing and are served directly — `/public/`, `/swagger/`, `/generated/swagger.json`, `/generated/app.css`, `/favicon.ico`. Every other path (including any real framework file) is routed to `index.php`, which serves the app or a uniform 404.

## Two Controller Paradigms

### PageController (HTML pages)
Extend `PageController`, implement `init()`, and route public action methods with `#[Web('/path')]` (registers GET and POST) or the single-verb `#[Get]`/`#[Post]`/… attributes. An action returns a View or a string, or echoes its output.

Output goes through a `PageView`, which wraps the action's content in a full HTML page template. The default PageView uses `templates/page.php`. Set it per controller with `$this->setPageView('MyPageView')` or app-wide with `PageController::setDefaultPageView()`.

Mark an action as AJAX with `#[Web('/path', ajax: true)]` — its content is sent as the response body without a PageView wrapper.

**Views can compose like a widget tree.** `View::toString()` just renders a template and returns a string, so a template can freely nest another View: `<?= (new ChildView(...))->toString() ?>`. The shared building block for this is `protected function renderNode(View|string $node): string` on `View` itself (stringifies a single node — calls `->toString()` if it's a View, casts to string otherwise). It's `protected`, not `private`, purely so `SingleChildView`/`MultiChildView` can call it — that same visibility also means a template *can* call `$this->renderNode(...)` directly if it tries to (every template runs inside `View::toString()`'s own method body via `include`, so PHP can't scope a method to "framework-internal, hidden from templates" the way some other languages' `internal`/package-private visibility could). Its docblock says so explicitly: never call it from a template with an arbitrary node — only `renderChild()`/`renderChildren()` are guaranteed to render the *correct* thing, since they're bound to `$this->child`/`$this->children` rather than taking a caller-supplied argument.

**`SingleChildView`/`MultiChildView`** (`core/view/SingleChildView.php`/`core/view/MultiChildView.php`) are where those actually live, one per class, so a widget's shape is obvious both from what it extends and from which method even exists on it:
- `SingleChildView` declares `public View|string $child` and a paramless `renderChild()` that renders it. `renderChildren()` isn't declared here at all.
- `MultiChildView` declares `public array $children` and a paramless `renderChildren()` that renders them in order. `renderChild()` isn't declared here at all.

Calling the wrong one for a widget's shape is a plain "call to undefined method" error — there's no runtime guard to maintain, because PHP's own method resolution already can't find a method that was never declared on that branch of the hierarchy.

A concrete widget extends whichever fits, calls `parent::__construct($child)`/`parent::__construct($children)`, then adds its own extra constructor params and `setTemplate()`. `PaddingView extends SingleChildView`; `ColumnView extends MultiChildView`; `TextView` (a leaf with no nested content at all) extends `View` directly. `PageView` is a `SingleChildView` too — its wrapped content (the action's rendered output, or a View passed directly) is its one child; its extra constructor params work exactly like `PaddingView`'s extra `$size` param.

**Every view carries an optional `id` and two template hooks.** The base `View` constructor takes `?string $id = null` — always the *last* constructor parameter on every concrete view (`new TextView('hi', 'greeting')`), so it's available everywhere but never required, and adding it never breaks an existing call. Two `public` methods back it in templates: `idAttr()` emits an escaped ` id="…"` (or `''`) for the root element, and `cssClass()` returns the view's own slugified class name (`TextView` → `text-view`, kept whole — the `View` suffix is *not* stripped) as a per-view styling hook. A template's root element combines them: `<span<?= $this->idAttr() ?> class="<?= $this->cssClass() ?>">`.

**`PageView` takes the primitives a page needs, not a `PageController`.** Its constructor is `(View|string $child, ?string $title, ?string $lang, ?array $bodyClasses, ?string $id)` — the template reads `$this->title` / `$this->lang` / `$this->bodyClasses` directly (all escaped at output), so it never reaches back into a controller or the session. `$bodyClasses` defaults to the currently matched route's classes via `Router::bodyClasses()` when omitted, so a routed page passes nothing for it. `PageController::renderAction()` is the composition root: it reads `getTitle()` / `Session::get('lang')` and constructs the `PageView`, for both a routed action (from `index.php`) and the catch-all fallback (`getDefaultControllerActionContent()`), which passes explicit classes via `Router::bodyClassesFor($controller, $action)` since no route matched there.

**A page's `<head>` gets two more methods, so no template hard-codes an asset path.** `stylesheetTag()` returns the complete escaped `<link>` for the generated stylesheet — or `''` when `ezmvc generate` hasn't built it yet, the normal state of a fresh clone — so a template just echoes it, the same contract `View::idAttr()` already has. It knows the file's location (`generated/app.css`, a `private const STYLESHEET_FILE` on `PageView`) and cache-busts the href with the file's mtime, which costs one `stat()` per page render. `assetUrl('public/site.css')` is the piece underneath it, public because a project's own hand-placed assets need exactly the same treatment: it prefixes only the **path** component of `Config::BASE_PATH`, never its host, so a page reached on a different hostname still links its own files.

**Body/CSS slugs go through one slugger.** `Core::slug('ThisIsAName')` → `this-is-a-name` is the single implementation behind Swagger tag slugs, page `<body>` classes (`Router::bodyClasses()` / `bodyClassesFor()` emit `controller-<slug> action-<slug>`, the `Controller` suffix dropped), and per-view `cssClass()`.

**Widget CSS is a co-located plain `.css`, concatenated at build time.** `ezmvc new view` writes a third file beside the template — `templates/<slug>.css` — holding one empty rule nested under the view's own class (`PaddingView` → `.padding-view`, exactly what `cssClass()` emits at runtime). Write the widget's styles inside that wrapper using **native CSS nesting** (`&.p-sm { … }`); there is no preprocessor. `ezmvc generate` concatenates every `templates/**.css` into `generated/app.css` — installed addons (`vendor/ezmvc/*/templates/`) first, the project's own `templates/` last, so a project rule wins over an addon's at equal specificity — and a page template links it with one `<?= $this->stylesheetTag() ?>`. Rules are copied verbatim; the only thing the build changes is that **CSS comments are stripped**, so the bytes a browser receives describe neither your directory layout nor the framework (a comment whose opener ends in `!` survives, the usual licence-header convention). Source `.css` files keep their comments — they are never served. Chunks are joined with a literal `\n`, not `PHP_EOL`, so the same sources build byte-identically on any platform. It lands in `generated/` rather than `public/` because it is a build artifact, not a hand-placed asset: gitignored and overwritten on every run, exactly like `routes.php` and `swagger.json` beside it, and `.htaccess` whitelists it by exact name the same way.

The build step is what makes addon styles possible at all: `.htaccess` serves only `public/`, so a stylesheet sitting next to its template — and especially one inside `vendor/` — can never be `<link>`ed directly.

**What the wrapper buys, and what it doesn't.** It stops one widget's rules colliding with another's. It is *not* encapsulation: `.padding-view { color: red }` still inherits into every nested widget, and nothing prevents a widget writing a selector that reaches into another's class. Scoping in the Angular sense — selector rewriting, per-instance attribute stamping — is deliberately not implemented: it needs a CSS compiler, and it would break "read the template, see the markup". SCSS was considered for the same reason and rejected; the file layout is identical either way, so swapping the concat step for a compile step later touches one CLI function and nothing else.

Composition works in either direction (a `SingleChildView` can hold a `MultiChildView` as its one child, and vice versa) without any special-casing, since both ultimately just call `toString()` on whatever they're holding — see `views/ColumnView.php`/`PaddingView.php`/`TextView.php` for a worked example.

`ezmvc new view <type> <Name>` scaffolds any of the four (`single`/`multi`/`leaf`/`page`). Each writes three files: the View class in `views/`, its template in `templates/`, and the co-located `templates/<slug>.css` described above.

**Never call `->toString()`/`->show()` on `$this` from within your own template.** Composition always calls one of these on a *child* instance (a different object) — never on the View that's currently rendering. `toString()`/`show()` must stay `public` (the dispatcher and `renderNode()` call `->toString()` on arbitrary View instances), so instead `View` tracks an `$isRendering` flag and throws a `LogicException` if either method is called again on the same instance while it's already rendering — this turns "template mistakenly re-renders itself" from an infinite-recursion hang into an immediate, clear error.

### ApiController (REST API)
Extend `ApiController`, implement `init()`, and route public action methods with `#[Get('/path')]`/`#[Post]`/`#[Put]`/`#[Delete]`/`#[Patch]` (see "Attribute-Based Routing and Swagger" below). Method names are free.

**Actions `return` a success or `throw` an error — they never `die()`.** This keeps an action a pure, testable function (a test calls it and asserts the returned `Response` / caught `ApiException`); the dispatcher in `index.php` is the *only* place that turns the outcome into bytes (`Response::send()`), via one try/catch:

- **Success** → return a `Response` (`core/Response.php`, a generic HTTP response of status + body + optional headers). Build it with the factories: `return Response::ok($dto)`, `Response::created($dto, "/users/{$id}")` (201 + Location), `Response::accepted($dto)`, `Response::noContent()` (204). An action may also `return` a bare object/DTO — the dispatcher wraps it as `Response::ok()` (200); returning nothing → 204.
- **Error** → `throw` an `ApiException` (`core/exceptions/ApiException.php`) — either the factories `throw ApiException::notFound('…')` / `::badRequest('…')` / `::unauthorized()` / `::forbidden()` / `::conflict()` / `::unprocessable()`, or `new ApiException($status, $msg)`, or a project subclass. The dispatcher maps it to its status and a uniform `ErrorResponse` body (`message` + request context; internals debug-gated). An *unexpected* exception (not an `ApiException`) becomes a generic 500 — real message only in debug. See the Services section.

**Authentication** is set in `app.php` via `ApiController::setDefaultAuthType()`, or per-controller via `$this->setAuthType()`. Available types:
- `AUTH_NONE` — no auth
- `AUTH_APIKEY` — `APIKEY` request header must match the controller's secret (`Config::API_KEY` by default)
- `AUTH_APIKEY_ALT` — same, but the secret is compared against `sha256(APIKEY)` (store the hash server-side, never the raw key)
- `AUTH_JWT` / `AUTH_JWT_NOEXP` — Bearer token in `Authorization` header, verified with `Jwt::parse()`. On success the whole decoded payload is available to the action as `$this->accessTokenPayload` (read any claim, e.g. `$this->accessTokenPayload->role`) and its `sub` claim as `$this->uid`

The JWT **algorithm** is a separate axis from the auth type (so `AUTH_JWT`/`AUTH_JWT_NOEXP` stay about expiry). It defaults to HS256; select RS256 with `ApiController::setDefaultJwtAlgorithm(Jwt::ALG_RS256)` (app.php) or per-controller `$this->setJwtAlgorithm(Jwt::ALG_RS256)`. The controller's **secret slot** (`setDefaultSecret()` / `setSecret()`) carries the key material for whichever algorithm is in effect — the shared HMAC secret for HS256, or the issuer's **PEM public key** for RS256 (verifying incoming tokens). So to accept an external identity server's RS256 tokens, set the default secret to that issuer's public key and the algorithm to RS256. The verifier is always told the algorithm and never reads it from the token, so mixing is safe from the RS256↔HS256 confusion attack.

Request validation: `#[ApiRequest(Foo::class)]` on the action validates the body before the action runs (`list: true` for a JSON array of them); `$this->checkRequestAs($schema)` does the same on demand. The validated result is passed to the action as its argument and also stored in `$this->request`, so declare it typed for IDE support — `post(Foo $request)`, or with `list: true`, `postMany(array $requests)` plus `/** @param Foo[] $requests */`. `#[ApiRequest]` stays the single source of the type; a parameter that doesn't match it, or a typed parameter on an action without `#[ApiRequest]`, fails with PHP's own `TypeError`/`ArgumentCountError`. Typed properties are checked by type and by any `Validators\*` attribute; an optional property that is absent keeps its default (or `null` when it has none). The same rules apply at any depth: a class-typed property (`public AddressDto $shipping`) takes a nested JSON object validated into that class; an `array` property with a `/** @var LineDto[] */` docblock — also `int[]`, `string[]`, `LineDto[][]` — validates every element (a property's `Validators\*` then apply to each scalar element); an `array` without `@var` takes any JSON array, elements unchecked — validate them in the action with `checkRequestAsListOf(new LineDto(), $this->request->lines)` if needed. Errors name the path (`Missing required field: lines[2].sku`); a value of the wrong JSON kind is a 400. `@var` must be a `/** */` docblock (reflection can't see `/* */`), class names resolve from the root namespace only (or write them fully qualified), and an unresolvable one is a `LogicException`. If docblocks are stripped at runtime (`opcache.save_comments=0`), a sent array property throws rather than passing unchecked. Swagger reads the same `@var` for the array's `items`. Untyped properties are the legacy mode: each default value is a regex, a `Validators\*` class name, a nested schema object, or `[]` (the field defaults to an empty array — patterned arrays are rejected with a `LogicException`).

## ORM (Entity)

Extend `Entity` and declare public properties matching the database column names. Implement `init()` to configure the entity:

```php
class User extends Entity {
    public $id, $name, $email, $created_at;

    protected function init() {
        $this->setTableName('users');  // optional if class name matches table
        $this->setIdField('id');        // default: first field
        // $this->setReadOnlyFields(['created_at']);
        // $this->setNumericId(false); // for string PKs
    }
}
```

**Attribute-based mapping** (optional): properties don't have to match the column name 1:1, and the table doesn't have to match the class name — `#[Column('db_name')]` on a property and `#[Table('db_name')]` on the class override the default. This is what lets scaffolded entities use camelCase properties (`$numericValue`) while the actual column stays snake_case (`numeric_value`):

```php
#[Table('users')]
class User extends Entity {
    public ?int $id = null;

    #[Column('created_at')]
    public string $createdAt = '';

    protected function init() {
        $this->setIdField('id');
    }
}
```

`#[Id]` and `#[ReadOnlyField]` are property-attribute alternatives to `setIdField()`/`setReadOnlyFields()`:

```php
#[Table('users')]
class User extends Entity {
    #[Id]
    public ?int $id = null;

    #[Column('created_at')]
    #[ReadOnlyField]
    public string $createdAt = '';

    protected function init() {
        // no setIdField()/setReadOnlyFields() calls needed
    }
}
```

Only one property may carry `#[Id]` — **composite/multi-column primary keys are not supported**; `EntityMeta` throws if more than one property has `#[Id]`. `init()` runs after `EntityMeta` has read the attributes. A single-valued setter overrides its attribute — `setIdField()` beats `#[Id]`, as `setTableName()` beats `#[Table]`. `setReadOnlyFields()` instead *adds* to the `#[ReadOnlyField]` properties (a de-duplicated union, and repeated calls accumulate), so declaring a field both ways is harmless and neither form can silently cancel the other; to make a field writable again, remove it from both.

`EntityMeta` builds its property→column map via reflection on `#[Column]` (falling back to the property's own name when absent); `#[Table]` is read the same way, falling back to the class name. The **entity-config** calls that reference this map — `setIdField()`, `setReadOnlyFields()`, `#[Id]`/`#[ReadOnlyField]` — take **property** names. `SearchCriteria`/`Op`, by contrast, put their field argument straight into SQL, so those take the real **column** names (this is what the generated `const NUMERIC_VALUE = 'numeric_value'` constants are for — use `Entity::COLUMN` in an `Op::eq(...)` rather than a bare string). Relation keys (`#[HasOne]`/`#[HasMany]`/`join()`) are column names too, for the same reason.

**CRUD methods**: `->get($id)`, `->store()`, `->update()`, `->save()` (upsert), `->delete()`, `->find(SearchCriteria)`, `->findFirst(SearchCriteria)`, `->fill($obj)`. `fill()`/`build()` assign every mapped field present in the input, the id and read-only fields included, so never pass them request data (`build($_POST)->save()` lets a client choose the id and overwrite any row) — map a validated Dto with the generated `fromDto()`, which skips the server-managed fields. The single-entity reads return **`static`**, not `Entity` — `(new User())->get(1)` is typed `User`, so properties autocomplete without a `/* @var */` annotation (see Known Gotchas for the subclass constraint that buys). `find()` returns a plain `array` with a `@return static[]` docblock, since PHP has no typed arrays. Static query helpers: `Entity::getById($id)`, `Entity::findAll($sc)`, `Entity::findOne($sc)`, and the scalar aggregates `Entity::count(?$sc)` / `Entity::exists(?$sc)` — both use the criteria's WHERE only (its `GROUP BY`/`ORDER BY`/`LIMIT` are ignored), hydrate nothing, and `exists()` short-circuits with `SELECT 1 … LIMIT 1` (cheaper than `count() > 0` when you only need presence).

Bind the DB connection once in `app.php`: `Entity::bindDefaultConnection($db->getConnection())`.

**Connection resolution has three tiers**, most specific first — `$this->_db` (this instance) → `$this->_meta->db` (this entity class) → `Entity::$_ddb` (the global default). One `getDatabase()` resolves all of them and every query goes through it, so a single binding covers get/find/store/delete/relations alike; when all three are null it throws `No database bound.`

Each tier has its own setter, and **only the middle one's scope is wider than its syntax suggests**:
- `Entity::bindDefaultConnection($conn)` — static, the global default. What `app.php` calls.
- `$entity->bindConnection($conn)` — **the whole entity class**, despite the instance syntax: it writes `$this->_meta->db`, and `EntityMeta` is cached one-per-class in `Entity::$_metas` and shared by every instance, present and future.
- `$entity->bindInstanceConnection($conn)` — that one object only. Chainable (returns `$this`), and `null` clears it so the next tier applies again.

Mixing the two is harmless in the normal case, because tier 1 wins: entities from a `{DbName}Db` accessor always carry an instance connection, so a class-level binding can't reach them, and a bare `new User()` picking up that binding is exactly what calling `bindConnection()` asked for. The one confusing combination is calling `bindConnection()` **on an entity that already has an instance connection** — the object you called it on doesn't change (tier 1 still wins) while every *other* instance of that class does, including ones constructed later. If you meant that one object, `bindInstanceConnection()` is the one to reach for.

An instance connection propagates to everything that instance produces: rows it hydrates, and relations it eager-loads (the related entity that runs the batched query also drives the recursive descent, so the whole subtree inherits it). That propagation is what makes the tier usable for a scoped connection rather than just the one object you bound.

The **static query helpers can't reach the instance tier** — `getById`/`findAll`/`findOne`/`count`/`exists` each build their own `(new static())`, so there is no instance to have bound. They resolve tier 2 then tier 3. Reach for the instance API (`(new User())->bindInstanceConnection($c)->get($id)`) when the connection matters.

When no instance connection is set, tier 1 propagates as `null`, so each related entity class falls through to *its own* class binding — which is what lets a multi-database setup built on `bindConnection()` keep each class on its own connection. When an instance connection *is* set, it wins over the related class's binding: most specific intent wins.

**The generated per-database class is the ergonomic way to use a specific connection.** `ezmvc scaffold` writes one file per database to `databases/` — `{DbName}Db`, holding a `Database` in a public `$db` property, with one accessor per table handing back an entity already bound to *that object's* connection:

```php
class CheckoutService {
    public function __construct(private ShopDb $shop) {}   // injected, so the dependency is visible

    public function run(int $id): Order {
        return $this->shop->db->runTransaction(function () {          // the connection
            $order = $this->shop->order()->get($id, depth: 2);        // generated accessor
            $n     = $this->shop->db->fetchColumn('select count(*) …');
            $order->update();                                        // writes back to the same connection
            return $order;
        });
    }
}
```

It **holds** a `Database` rather than extending one, and that is a safety property rather than a style choice. Extending would put every table name into the same namespace as `Database`'s API, and the collision runs **both ways**: a table called `transaction` or `commit` would override an inherited method, *and* a method added to `Database` later would be silently overridden by an accessor generated earlier — breaking `Database`'s own internals, since `runTransaction()` calls `$this->commit()`. PHP has no way to reach a shadowed parent method from a call site (there is no `(ClassName)$obj` cast; only `ReflectionMethod::invoke` gets there), so the damage wouldn't even be fixable by the caller. Two independently evolving namespaces can't be kept disjoint, so they're kept apart instead.

Holding it in a **property** is what closes the gap completely: PHP keeps properties and methods in separate symbol tables, so `$shop->db` and a generated `$shop->db()` for a table named `db` coexist, and the class inherits no names at all. Any table name whatsoever generates a valid accessor — verified against `transaction`, `commit`, `rollback`, `execute`, `fetch_all`, `get_time`, `get_connection`, `run`, `last_insert_id` and `db`. The only way left to collide is declaring a method twice in one class, which PHP rejects at compile time with `Cannot redeclare`.

This is also where **hand-written cross-table queries belong** — the joins/aggregates/reports the `Database` escape hatch exists for live here rather than inlined in services. The file is written once and skipped on re-scaffold for exactly that reason; `--overwrite` regenerates it and **discards anything added by hand**, so add accessors for new tables yourself (three lines) rather than regenerating.

Accessor names are mechanical — the camelCase of the table name (`user_account` → `userAccount()`), with no pluralisation guessing. Register it like any other service, and note that `addScoped` gives a cron runner a genuinely separate connection per job:

```php
$container->addSingleton(Database::class, fn() => new Database(Config::DB_HOST, Config::DB_USER, Config::DB_PASS, Config::DB_NAME));
$container->addSingleton(ShopDb::class);   // autowired: its one argument is the Database above
```

**Entity vs. Dto vs. Response/Request — which to use where, and where each lives:**
- `entities/` — Entity. DB-bound, one per table.
- `databases/` — `{DbName}Db`. One per database, generated. Holds a `Database` as `$db`; an accessor per table plus your own cross-table queries.
- `dtos/` — `{Table}Dto`. One per entity, a single class mirroring it 1:1, used for both input and output on simple single-entity API endpoints (`Entity::toDto()` / `Entity::fromDto()`). Named *after the entity*.
- `requests/` / `responses/` — hand-written, **exclusively API contracts**: the shape an endpoint publishes when no single entity's mirror expresses it — a payload spanning more than one entity, an action-specific shape, or simply a narrower view than the table. Named *after the operation*, not an entity (e.g. `CheckoutRequest`, `DashboardSummaryResponse`).

**A contract doesn't have to live in `responses/` — a Dto is a perfectly good one.** These directories aren't "contract" vs "internal": `requests/`/`responses/` hold *nothing but* contracts, while a `{Table}Dto` is both an entity mirror and, for most simple endpoints, the published shape itself. There's deliberately no hand-written layer sitting in front of every Dto just to decouple the wire format from the schema — that only pays for itself when external consumers can't be updated alongside a migration, and for the projects this framework targets you own both ends.

So the question isn't "is this a contract?" — both are. It's **whether the generated mirror already is the shape you want to publish.** If yes, use the Dto and don't write a second class. If not, write the Request/Response.

**Narrowing a Dto is two edits**, since `toDto()`/`fromDto()` map one explicit assignment per property: delete the property *and* its line in the mapping. Leave the assignment behind and PHP re-creates the property dynamically, so the field is still serialized — but an IDE flags assigning to an undeclared property, so it doesn't get past the editor. (That's also why there's no `__set` guard on generated Dtos: it would make the class accept any property and silence exactly that warning, while leaving a line in `toDto()` that reads as though it maps the field and doesn't.) `--overwrite` restores both files, so re-scaffolding undoes the narrowing — use `--table=` to scope the run.

For `PageController` pages, passing the Entity straight into a View/template is fine regardless of the above — a JSON API response serializes every public property automatically (so an Entity field that shouldn't be public, or that changes with the schema, leaks unless there's an explicit allow-list — that's what Dto/Response are for), but a template only renders whatever fields it explicitly references, so there's no automatic-serialization exposure risk to guard against on the page side.

**`form-request` classes (`core/Request.php`, extended by `requests/{Name}Request.php` when generated with `ezmvc new form-request`) are declared like API request classes** — typed public properties with `Validators\*` attributes — and validated by the same engine, `core/SchemaBinder.php`, in form mode:

```php
class RegisterForm extends Request {
    #[Required('Please enter your email')]
    #[Validators\Email]
    #[ErrorMessage('Not a valid email address')]
    public string $email = '';

    public ?int $age = null;              // cast; "12x" is a format error
}
```

Form mode differs from the API in four ways: values are trimmed and an empty field counts as absent; **every** error is collected (`isValid($field)`, `getErrorMessages()`), not just the first; a text field with no `Validators\*` is checked as a single line of at most 255 characters without `<>;'"`; and fields need a **default**, because the template reads them before the form is sent — so "required" is the explicit `#[Required('message')]` (in `core/attributes/Validation.php`, with `#[ErrorMessage('message')]` for a type or format failure; both work on API request classes too, where the first error's message becomes the 400's). A field that fails keeps its default; the submitted value stays available for redisplay. Fields are read from POST by default (`setMethod(self::METHOD_GET)` etc. in the optional `init()`); cross-field checks go in the optional `postCheck()`, which runs once every field passed. Messages appear only where declared, or for every failure with `setAutoErrorMessage()`.

**CSRF:** a form sent by POST must carry the session's token — put `<?= EzMvc\Request::csrfField() ?>` inside every `<form method="post">`. A missing or wrong token makes the form invalid with an error on `_csrf` ("The form has expired — please submit it again."), so the action's usual `isValid()` branch re-renders it; nothing throws. One random token per session, created on first use and kept across `Session::regenerate()`, so several tabs and the back button keep working. GET-sourced forms (search) aren't checked. A form that receives cross-site POSTs by design (a payment provider's callback) opts out with `$this->setCsrfCheck(false)` in `init()`. The token lives in the session, so using the check without a started session throws a `LogicException` rather than failing every POST as expired; page controllers start it in `index.php`. API controllers don't need it: they authenticate with headers, not cookies.

**Redisplay a submitted value with `$form->esc('field')`, never the raw property.** `esc()` returns the value as submitted — so an invalid entry, like `"12x"` for an `int`, comes back for correction — escaped with `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')`, the same escaping `templates/components/text.php` does for `TextView`: explicit, at the point of output, not automatic on the property (which would corrupt the value for business logic and DB storage that need it raw).

**SearchCriteria + Op** build parameterized WHERE/HAVING clauses. `Op::*` (in `core/orm/SearchCriteria.php`) are factory methods producing condition-tree nodes; `SearchCriteria::where(...)` AND-s the top-level nodes, and `Op::and()`/`Op::or()` nest to any depth — the joiner lives on the grouping, not on each operator:
```php
$sc = SearchCriteria::where(
    Op::eq('status', 'active'),
    Op::or(Op::eq('role', 'admin'), Op::eq('role', 'mod')),
    Op::gt('created_at', '2024-01-01'),
)->orderBy('name')->limit(20);
$results = (new User())->find($sc);
```
Operators: `eq/neq/gt/gte/lt/lte`, `like/notLike` plus `contains/startsWith/endsWith` (LIKE with `%`/`_` in the value escaped to literals), `in/notIn` (empty `in([])` matches nothing, `notIn([])` matches everything), `between/notBetween`, `isNull/isNotNull`, `and/or/not`, and `raw($sql, ...$params)` for an arbitrary fragment with explicitly-bound params. Chainable clauses: `->orderBy($field, $asc = true)` (call repeatedly), `->groupBy(...$fields)`, `->having(...$nodes)`, `->limit($n, $offset)`.

**Values are always parameterized** (bind types inferred per value: int/bool→`i`, float→`d`, else `s`); **column names and the `raw()` fragment are not** — they're concatenated as-is, so they must be developer-supplied, never request input.

## Relations (eager loading)

An Entity can declare relations that load into a **non-column property**. Declare them two equivalent ways — an attribute on the property, or `$this->join(...)` in `init()` (same attribute-vs-imperative duality as `#[Table]`/`setTableName()`):

```php
#[Table('users')]
class User extends Entity {
    public ?int $id = null;
    public string $name = '';

    #[HasMany(Order::class, 'user_id')]   // 1:N -> Order[]
    public array $orders = [];
    #[HasOne(Profile::class, 'user_id')]  // 1:1 -> Profile|null
    public ?Profile $profile = null;

    protected function init(): void {
        // imperative equivalent, if you don't want the attribute:
        // $this->join('orders', Order::class, 'user_id', many: true);
    }
}
```

The relation carries the related class, the **foreign key** (column on the *related* table pointing back), and an optional **local key** (column on this table, default the id column) — both column names, since the loader uses them in SQL. A `#[HasOne]`/`#[HasMany]`/`join()` property is kept out of the column map entirely (like a `_`-prefixed property), so it's never selected/inserted/updated.

**Loading is eager and automatic**: `get()`/`find()`/`findFirst()` populate every declared relation with one batched `WHERE fk IN (…)` query per relation (built on `Op::in`) — O(relations) queries, never N+1. This is strategy-B eager loading, *not* a SQL JOIN: each query hits one table, so `SearchCriteria`'s `WHERE`/`GROUP BY`/`HAVING` can't reach a related table's columns. For cross-table filtering/aggregation, drop to `Op::raw` with a subquery or the `Database` escape-hatch (`fetchAll()` with hand-written JOIN SQL).

**Depth** is a trailing argument to `get($id, $depth)` / `find($criteria, $params, $depth)` / `findFirst(…, $depth)` (and the static `getById`/`findAll`/`findOne`), defaulting to **1** — one level of relations. `$depth = 0` loads none; higher values descend into the loaded rows' own relations (`$user->orders[0]->items` at `depth: 2`). **Cycle safety**: the loader threads the ancestor chain (each entity's `class#id`) down the recursion, and a related row already on that path is *attached but not descended into* — so a bidirectional/self relation terminates instead of looping (the back-reference is populated one hop, its deeper relations stay at their `null`/`[]` default). There's no per-relation-path control (Eloquent's `with('orders.items')`); it's a single global depth.

**Exclude** is the final argument (`get($id, $depth, $exclude)` etc.) — an array of entity class-strings whose relations are skipped entirely at every level, e.g. `->find($sc, depth: 3, exclude: [AuditLog::class, Attachment::class])`. An excluded relation is left at its `null`/`[]` default and never queried (leading `\` is normalized, so `Foo::class` and `\Foo` both match). Use it to prune heavy branches out of a deep load.

Only top-level `get()`/`find()` load relations — internal fetches like `save()`'s existence check stay bare. Related entities are independent Entities: no cascade save/delete, you persist them yourself.

## Database

`Entity` is the primary way to touch the DB, but it only models single-table CRUD. `Database` (`core/orm/Database.php`) is the escape hatch for everything else — joins, aggregates, reports — with the same parameterized-query safety, so you never fall back to string-concatenating values into SQL.

Wire up the connection once in `app.php`. Two interchangeable front doors to the *same* implementation:
- **`StaticDatabase`** — a static facade for apps that prefer a global connection: `StaticDatabase::init(Config::DB_HOST, Config::DB_USER, Config::DB_PASS, Config::DB_NAME)`, then `StaticDatabase::getConnection()` / `StaticDatabase::fetchAll(...)` anywhere. This is what the sample `app.php` uses (`Entity::bindDefaultConnection(StaticDatabase::getConnection())`).
- **`Database`** — the instance form: `$db = new Database(...)`, then pass it around (e.g. into a service constructor) rather than reaching for a global.

`StaticDatabase` holds a single `Database` and delegates every call to it, so all the real logic lives in `Database` alone — pick whichever style fits; there's no behavioral difference. The constructor enables `mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)`, so any DB error throws (caught by `index.php`'s top-level handler) rather than returning a falsy result to check by hand; it also sets the connection charset to `utf8mb4`.

**Query helpers** — all take a SQL string with `?` placeholders and an array of bind values (types inferred per value: int/bool→`i`, float→`d`, else→`s`). Reach for the raw `getConnection()` only if you need explicit blob/decimal binding.

| Method | Returns |
|---|---|
| `fetchAll($sql, $params)` | All rows as associative arrays (`[]` when none) |
| `fetchOne($sql, $params)` | First row as an associative array, or `null` |
| `fetchColumn($sql, $params)` | First column of the first row (a scalar), or `null` |
| `execute($sql, $params)` | Affected-row count for an INSERT/UPDATE/DELETE |
| `lastInsertId()` | Auto-increment id from the most recent INSERT |
| `getTime($offsetMinutes = 0)` | DB server's `now()`, shifted by a signed minute offset |

`fetchAll`/`fetchOne`/`fetchColumn` use `get_result()`, so they need the `mysqlnd` driver — the same requirement `Entity::get()` already has.

**Transactions**: the low-level `beginTransaction()` / `commit()` / `rollback()` trio is there, but prefer `runTransaction(callable $fn)` for the common case — it runs `$fn` (passed the `Database`), commits its return value, and on any thrown `Throwable` rolls back and rethrows, so a forgotten rollback can't happen:
```php
$db->runTransaction(function (Database $db) {
    $db->execute('update accounts set balance = balance - ? where id = ?', [$amount, $from]);
    $db->execute('update accounts set balance = balance + ? where id = ?', [$amount, $to]);
});
```

## Services (optional)

MVC's "Model" was always meant to mean domain data *and* business logic together, not just persistence — this framework's `Entity` narrowed "Model" down to mean "DB-bound object," which leaves nowhere for logic that spans multiple entities, coordinates side effects (email, external APIs), or just doesn't belong to persistence. A **Service** is that other half of Model, given an actual home instead of leaking into Controllers or Entities.

**Optional, not required.** A two-line CRUD action calling an Entity directly needs no service. Reach for one when there's real orchestration to do — multiple entities, side effects, business rules that would otherwise bloat a Controller action or get smeared across Entity methods.

**Shared by both Controller types, never coupled to either.** A service doesn't know or care whether it was called from a `PageController` or an `ApiController` — that's the entire point of extracting it. Concrete constraints:
- Location/naming: `services/{Name}Service.php`, class `{Name}Service` — same convention as `entities/{Name}.php`, `dtos/{Name}Dto.php`.
- No `PageController`/`ApiController`/`View`/`Response` type anywhere in a service's method signatures, no building a `Response`, no reading `$_GET`/`$_POST`/`$_SESSION` directly — a Controller extracts whatever input is needed and passes it in as plain arguments. (Throwing an `ApiException` for an expected failure is fine in the pragmatic mode — that's the sanctioned cross-layer error signal, see "Throw for failure" below.)
- Entities, other services, and infrastructure utilities (`Mail`, `HttpClient`, `SearchCriteria`, `Calendar`) are all fine to use in the pragmatic mode — those aren't "other layers" in the MVC-boundary sense, they're tools, same category as an Entity running a query. The clean mode puts all but `Calendar` behind ports — it is date arithmetic, though a rule that depends on "now" should take the time as an argument rather than let `new Calendar()` read the clock. See "Pragmatic mode and clean mode" below.
- **Plain instantiated classes, no base class.** `Entity`/`View`/`Controller` have substantial base classes because they share real runtime behavior (CRUD, template rendering, routing). A service has no comparable shared behavior — every service's methods are domain-specific, there's no shared lifecycle hook, no shared state. A base `Service` class would be an empty marker with nothing in it. Services follow the same "plain, unrelated classes" precedent `Dto`/`Request`/`Response` already use, not the Entity/View/Controller one. Instantiate directly: `(new UserService())->register($request)`, or register it with the `Container` and let a controller type-hint it (see Dependency Injection below) — a service is a plain class either way, so both work and neither is a framework base class in disguise.
- **Return real, typed values directly — no wrapper class.** `public function register(UserRequest $request): User` is the whole pattern. This is also what gives you full IDE typing on the result with zero extra ceremony (no generics — PHP doesn't have them — and no per-operation result class).
- **Throw for failure.** How the throw is handled depends on the failure's kind:
  - **Unexpected** errors (a plain `\Exception`/`Error` — a DB blowup, a bug) need no handling. They bubble to `index.php`'s dispatch boundary and become a generic **500** — the real message/trace shown only in debug (`Core::setDebug()`), never leaked in production.
  - **Expected** failures that map to an HTTP status ("not found", "already exists", a rejected value) — throw an **`ApiException`** (`core/exceptions/ApiException`), which carries a `$status`. Throw it directly (`throw new ApiException(409, 'Email already taken')`) or, better, a project subclass with the status baked in (`class NotFoundException extends ApiException` → 404, under `exceptions/`). The `ApiController` dispatch boundary catches it, returns its `$status`, and renders its message into an `ErrorResponse` — so unlike a 500, **its message IS shown to the client**. No per-action `try`/`catch` needed: throw from a service or entity (with no transport knowledge beyond the status), and it just becomes the right HTTP error. This is the API-side default.
  - When a failure needs **action-specific presentation** — chiefly `PageController`s, where the *same* failure re-renders a *specific* form with its submitted state — catch it in the action and translate. `ApiException` auto-mapping is the default; a local `try`/`catch` is the override for an action that wants to handle a failure its own way:
    ```php
    // ApiController: usually nothing to catch — a thrown ApiException maps itself.
    return Response::ok((new UserService())->register($request)->toDto());

    // PageController: catch to re-render the form with its errors.
    try {
        return new WelcomeView((new UserService())->register($request));
    } catch (ValidationException $e) {
        return new RegisterFormView($e->errors);
    }
    ```
  `core/exceptions/ApiException` is the **only** framework-provided exception — it's the contract the boundary understands. A purely project-defined exception with no HTTP mapping that a controller catches itself (e.g. `ValidationException extends \Exception` carrying field errors, as above) belongs under the project's own `exceptions/`, not `core/exceptions/`.

### Pragmatic mode and clean mode

The rules above describe the **pragmatic mode**, the default: a service uses `Entity` as its domain model, calls `Mail`/`HttpClient`/`SearchCriteria` directly, takes an API request class as input and throws `ApiException`. That is layered MVC, not Clean Architecture — `Entity` extends a framework class and holds a `\mysqli`, so a service built on it depends on the framework and the database driver. For a small project that coupling costs less than the boundaries that would remove it.

A project that wants Clean Architecture opts in per service; nothing in the framework changes. A service is clean when:

- **Its domain objects are plain classes.** `Entity` appears only inside persistence adapters, never in a service's signatures.
- **Every I/O edge is an interface the service owns** — a port, injected through the constructor. Persistence: a repository with intention-named methods (`findActiveByRole()`), so `SearchCriteria` stays inside the adapter. Mail: inject `MailSender` rather than calling the static `Mail::send()`. Outgoing HTTP: a port named after what it does (`PaymentGateway`), its adapter using `HttpClient`. `Logger` is already an interface.
- **Its failures are domain exceptions** (`OrderNotFound`). The controller maps them to an `ApiException` or a re-rendered form, so no HTTP status enters the service.
- **Its input is its own** — plain arguments or a command class the service defines, never a `requests/` class. The controller maps the validated request onto it.

Controllers are then thin adapters: validate, map in, call, map out.

The files fit the existing classmap: domain classes and port interfaces in `services/`, domain exceptions in `exceptions/`, repository adapters in `databases/` beside the `{DbName}Db` they wrap. Other adapters (mail, HTTP) go in a directory of the project's choosing, added to `composer.json`'s `autoload.classmap`.

```php
// services/
interface OrderRepository {
    public function find(int $id): Order;          // Order: a plain domain class
    public function save(Order $order): void;
}

// databases/
class SqlOrderRepository implements OrderRepository {
    public function __construct(private ShopDb $shop) {}

    public function find(int $id): Order {
        $row = $this->shop->order()->get($id) ?? throw new OrderNotFound($id);
        return new Order($row->id, $row->status);
    }
    // save(): map Order onto the entity, then ->save()
}

// app.php
$container->addScoped(OrderRepository::class, SqlOrderRepository::class);
```

A repository isolates only when its port returns domain objects. One that hands back an `Entity` leaks the framework and `\mysqli` straight through the interface, which is why the framework generates no per-table repositories: the mapping is the point, and it is project-specific. The adapter itself still depends on `\mysqli` through `Entity` — Clean Architecture puts that dependency exactly there.

Both modes coexist in one project: pragmatic where a service is CRUD glue, clean where its rules are worth testing without a database.

## Dependency Injection (optional)

`core/Container.php` is a service container in the .NET Core mould: registration is explicit, the lifetime is the method name, and resolving something unregistered is an error rather than a guess.

**Optional in the real sense** — a project can register nothing: `(new UserService())->register($request)` works without the container, and a controller with no constructor costs one cached reflection lookup per request.

Wire it in `app.php`, beside the existing `Entity::bindDefaultConnection` / `Mail::setSender` calls:

```php
$container = Container::root();
$container->addSingleton(Database::class, fn() => new Database(Config::DB_HOST, Config::DB_USER, Config::DB_PASS, Config::DB_NAME));
$container->addScoped(PaymentGateway::class, StripeGateway::class);   // interface -> implementation
$container->addScoped(UserService::class);
$container->addTransient(TokenMinter::class);
```

A controller then declares what it needs, and the dispatcher supplies it:

```php
class UserController extends ApiController {
    public function __construct(private UserService $users) {}

    #[Get('/users/{id}')]
    public function getOne(): Response {
        return Response::ok($this->users->find($this->routeParam('id'))->toDto());
    }
}
```

**Registration is mandatory, exactly as in .NET.** `get()` on an unregistered id throws a `\LogicException` naming the id and the `app.php` line that would fix it; there is no implicit autowiring and therefore no "default lifetime". So `app.php` stays a readable manifest of what the app is actually made of, and a mistyped type-hint is an error instead of a silently constructed object.

**Controllers are the one exception**, the same exception ASP.NET Core makes via `ActivatorUtilities`: the dispatcher builds them with `Container::create()`, which constructs an *unregistered* class and resolves only its constructor arguments from the container. A controller class never needs a registration line — its dependencies do. `create()` caches nothing, so every request gets a fresh controller.

### Logger and MailSender belong to the core

The core logs through `Core::logger()` and mails its fatal-error report through `Mail::send()`, and it must work with the container unused — `Core::init()` logs before `app.php` has registered anything. So for these two the static facade owns the instance and the container only exposes it: `Container::root()` comes with `Logger` and `MailSender` pre-registered, resolving to `Core::logger()` and `Mail::sender()`. Configure them with `Core::setLogger()` / `Mail::setSender()` in `app.php`, and a `Logger` or `MailSender` type-hint receives exactly what the core uses — no registration line. The registrations are transient, so an injection always gets the facade's *current* instance.

Registering either id yourself, in any scope, throws a `LogicException` naming the setter to call instead when debug mode is on (`Core::setDebug()`), so the mistake surfaces the moment `app.php` runs. In production the registration is ignored and the same message is logged as a `warning` under `Container` — once per request, since `app.php` runs on every one — so a stray line reaching a live site doesn't turn every request into a 500. A second registration is how the split happens: services logging to one place while the core's `REQ`/`RES`/`Dispatch` lines go to another, or services sending through SMTP while the error report goes through `mail()`. Test doubles go through the setters too.

### A different implementation for one consumer

A service that needs its own mailer or logger — a separate log file, a different mail provider — gets it from a factory registration of **that service**, which leaves the app-wide default alone. The service still type-hints the interface; `app.php` decides what it receives:

```php
$container->addScoped(NewsletterService::class, fn(Container $c) => new NewsletterService(
    new SendGrid(Config::SENDGRID_KEY),
    new FileLogger('logs/newsletter'),
    $c->get(Database::class),
));
```

Framework code the service calls still uses the facades — `HttpClient` logs its hook failures through `Core::log()`, for instance — so those lines go to the app log, not the service's.

When the implementation can only be built at runtime — a SendGrid key loaded per user — inject a factory instead of the instance. The factory interface belongs to the project, named after what the service needs, since construction arguments differ per backend:

```php
interface MailSenderFactory {                       // services/
    public function forApiKey(#[\SensitiveParameter] string $apiKey): MailSender;
}

class SendGridMailSenderFactory implements MailSenderFactory {
    public function forApiKey(#[\SensitiveParameter] string $apiKey): MailSender {
        return new SendGrid($apiKey);
    }
}

$container->addSingleton(MailSenderFactory::class, SendGridMailSenderFactory::class);
```

If the key is stored data rather than something the service receives, let the factory look it up (`forUser(int $userId)`), so the service never handles the secret. A container scope is the wrong tool for either case: it decides how long an instance lives, not which implementation is chosen.

### The three lifetimes, and what they mean in a shared-nothing runtime

| Method | Instance per |
|---|---|
| `addSingleton($id, $concrete = null)` | process — always built and cached on the root scope, even when first requested from a child |
| `addScoped($id, $concrete = null)` | scope |
| `addTransient($id, $concrete = null)` | `get()` call |

PHP is shared-nothing: the process starts at `index.php` and dies after the response. **A singleton cannot outlive a request** — no amount of container design makes a `mysqli` handle survive into the next one.

So in web dispatch the root container **is** the request scope, and `addSingleton` and `addScoped` yield the same object. That is not a defect to work around; it is what the runtime permits. A project that only serves HTTP can use `addSingleton` throughout and never think about scopes.

The distinction earns its keep where one process runs several units of work — a cron runner, a CLI batch — and calls `createScope()` per unit:

```php
$container = Container::root();
foreach ($jobs as $job) {
    $scope = $container->createScope();       // fresh scoped services per job
    $scope->get(JobRunner::class)->run($job); // Database stays singleton across all of them
}
```

This is also the one place the framework deliberately diverges from .NET, which forbids resolving a scoped service from the root provider (there it would be promoted to singleton and never disposed). Here the root *is* a scope by design, and the disposal hazard behind that rule doesn't exist — PHP has no `IDisposable`, and process teardown frees everything.

`$concrete` takes four forms: `null` (construct `$id` itself), a class-string (interface → implementation), a `\Closure` factory receiving the `Container`, or an already-built object.

### What it catches

- **Captive dependencies** — a singleton depending on a scoped service pins that service for the whole process, so every later scope silently shares the first scope's instance. Building such a singleton throws, naming both classes. **Debug-only** (`Core::setDebug()`), mirroring .NET's Development-environment scope validation: production pays nothing, and with mandatory registration this is the error the container's own defaults make easiest to commit.
- **Circular dependencies** — `A -> B -> A` throws with the full chain, instead of a PHP stack-overflow fatal carrying no useful message.
- **Unresolvable constructor parameters** — the message names the class *and* the parameter, and distinguishes "nothing registered for `Foo`" from "no class type hint and no default value". A parameter with a default value is allowed to be unregistered (the default is used); a registered type always wins over a default. Union-typed and builtin parameters carry nothing to resolve by, so they fall to their default or throw.

### Deliberately not included

No setter or property injection (hidden dependencies defeat the point). No `#[Inject]` attribute — scalars come from `Config::` constants, which are already reachable everywhere. No auto-scanning of `services/`. No compiled container: constructor *plans* are cached per class per process, which removes repeat reflection without adding a build step or a staleness failure mode. No container injection into actions — constructor injection covers dependencies. The one argument an action can receive is its own validated `#[ApiRequest]` body (see the ApiController section), which costs nothing extra: `performRequestCheck()` already reflects the action to read that attribute.

No `Psr\Container\ContainerInterface` either: it needs a `psr/container` dependency, and shipping zero third-party bytes is worth more here. `get()` and `has()` are deliberately PSR-11-compatible in name and behavior, so a project that wants the interface adds it in one line.

**`Entity` is not wired into the container** — it resolves its own connection through the three tiers described in the ORM section, and `app.php` still calls `Entity::bindDefaultConnection()`. So a service that reaches for `User::getById()` has a dependency the container cannot see, substitute or scope: the DI improvement here is *visibility* at the service boundary (`__construct(private Database $db)` states what `StaticDatabase::fetchAll()` hid), not substitutability of persistence. There is no generated repository layer — a repository wrapping `User::getById()` buys a swappable constructor argument, not isolation, and isn't worth one class per table. Where a service has logic worth testing, write a port and an adapter that returns domain objects, as described under "Pragmatic mode and clean mode" in the Services section. `bindInstanceConnection()` is the tier a future container-registered connection scope would use.

For tests, `Container::reset()` drops the root scope and every cached plan; `addSingleton($id, $fake)` injects a pre-built double.

## Code Generation

`OrmScaffolder::createOrm($conn, $entitiesDir, $dtosDir, $overwrite, $tables)` introspects the database and generates a typed Entity + Dto class per table (see the Entity/Dto/Response/Request split above). Entity and Dto properties are all camelCased and consistent with each other; where a property's camelCase name differs from the actual DB column, the Entity property carries `#[Column('db_name')]`, and the class itself carries `#[Table('db_name')]`. The primary key (the `id` column, else the first one) carries `#[Id]` and a `create_date` column carries `#[ReadOnlyField]`; `init()` also names both through `setIdField()`/`setReadOnlyFields()`. Because read-only fields merge, a `#[ReadOnlyField]` added to a scaffolded entity later takes effect alongside the generated setter call. Each Entity also gets one `const` per column (e.g. `const NUMERIC_VALUE = 'numeric_value';`, always the real column name) so `SearchCriteria` calls can use `TestEntity::NUMERIC_VALUE` instead of a bare string. The Entity gets `toDto(): {Table}Dto` / static `toDtoList(array $entities): array` (mapping its own fields onto the Dto) and a static `fromDto({Table}Dto $dto): self` factory (mapping the Dto's fields onto a new Entity) — since Entity/Dto property names match, these are simple 1:1 assignments. Server-managed fields (the auto-increment id, `create_date`, `delete_date`) are nullable in the Dto even when the column is `NOT NULL`, so a client can omit them when creating a record; `fromDto()` only ever reads the writable fields, so a client attempting to set them is silently ignored rather than validated against. Fields are typed from the actual column types (nullable columns get `?type`, `tinyint(1)` becomes `bool`), and tables with a `delete_date` column get a `deleteLogical()` soft-delete method. **The Dto's properties also get a `Validators\*` attribute inferred from the column's mysqli type/length/unsigned-ness**, e.g. a nullable `varchar(32)` becomes `#[Validators\Text(maxLength: 32, optional: true)]`; a plain (non-`UNSIGNED`) int column becomes `#[Validators\Integer(signed: true)]` — MySQL ints/decimals are signed by default, but `Validators\Integer`/`Decimal` default to `signed: false`, so the generator has to emit `signed: true` explicitly for the common case rather than the unsigned one. Server-managed fields get `optional: true` for the same reason they get `?type` — a client can omit them on create. BLOB/binary columns get no attribute at all (skipped, not guessed) since there's no safe text-character-class mapping for arbitrary binary data. Pass `$tables` to scaffold specific tables instead of every table in the database. Run it once to scaffold; edit generated files as needed.

**A table counts as scaffolded if *either* its Entity or its Dto exists**, and then neither is rebuilt. That's deliberate rather than an oversight: **an entity doesn't have to have a Dto.** Delete the Dto for a table with no API surface and later runs leave it deleted, instead of recreating a class you've already decided you don't want. (The corollary is that `--overwrite` regenerates both, so refreshing entities after a migration brings a deliberately-deleted Dto back — delete it again, or scaffold just the tables you mean with `--table=`.)

`OrmScaffolder::createDbClass($conn, $databasesDir, $className, $overwrite, $tables)` generates the per-database class described in the ORM section — `{DbName}Db` holding a `Database` as a public `$db`, one accessor per table. The class name comes from `SELECT DATABASE()` (so it works whether the connection came from `Config.php` or explicit flags) through the same `tableToClassName()` the entities use, plus a `Db` suffix: `orm_test` → `OrmTestDb`. It runs after the per-table loop because it needs the full table list, and it reuses `snakeToCamel()` for accessor names. There is no reserved-name list, because composition leaves nothing to reserve — the only guard is a suffix for two tables whose camelCased names would coincide (`user_account` and `userAccount`), which would otherwise be a duplicate-method compile error. Unlike the Entity/Dto files, this one is *meant* to be extended by hand, so `$overwrite` carries a real cost.

The CLI's `ezmvc scaffold` command wraps both: it resolves a `\mysqli` connection (from the project's own `Config.php` by default, or `--host`/`--db`/`--user`/`--pass` for multi-db setups), calls `OrmScaffolder::createOrm()`, then `createDbClass()`. `--db-class=ShopDb` overrides the derived class name and `--no-db-class` skips it entirely — see `ezmvc/cli`'s README for the full flag list.

## Attribute-Based Routing and Swagger (ApiControllers)

Controllers use two sets of PHP 8 attributes:

**Routing** (`core/attributes/Route.php`): `Get`, `Post`, `Put`, `Delete`, `Patch`, and `Custom('/path', 'PURGE')` for any other HTTP method — determine how requests are dispatched at runtime. A `Custom` route is routed but not documented in Swagger, and OPTIONS never reaches one (`index.php` answers every OPTIONS request with the CORS headers); a browser only sends a non-standard method cross-origin if it is listed via `ApiController::setEnabledMethods()`.

**Swagger documentation** (`core/attributes/Swagger.php`): read only by `ezmvc generate`, never at runtime.

| Attribute | Target | Purpose |
|---|---|---|
| `#[ApiTag('name', 'desc')]` | class | Swagger group name (defaults to class name slug) |
| `#[ApiAuth('jwt'\|'apikey'\|'none')]` | class or method | Auth scheme; method overrides class |
| `#[ApiResponse(Foo::class)]` | method | Response body schema (repeatable) |
| `#[ApiResponse(Foo::class, 200, true)]` | method | Array response (`Foo[]`) |
| `#[ApiRequest(Foo::class)]` | method | Request body schema |
| `#[ApiPathParam('name', ApiType::INTEGER, 'desc')]` | method | Required path parameter (repeatable) |
| `#[ApiQueryParam('name', ApiType::INTEGER, 'desc')]` | method | Optional query parameter (repeatable) |

A parameter's type is an `ApiType` constant — `STRING` (the default), `INTEGER`, `NUMBER`, `BOOLEAN`; a plain string like `'integer'` works too. Arguments can be named, to skip the type: `#[ApiQueryParam('q', description: 'Search')]`. The generator reads attributes as source text, so it resolves `ApiType::X` itself; an attribute must stay on one line.

```php
#[ApiTag('users', 'User management')]
#[ApiAuth(ApiAuth::JWT)]
class UserController extends ApiController {
    public function init(): void {}

    /**
     * Get all users
     */
    #[Get('/users')]
    #[ApiResponse(UserResponse::class, 200, true)]
    public function getAll(): Response { return Response::ok(null); }

    #[Get('/users/{id}')]
    #[ApiPathParam('id', ApiType::INTEGER, 'User ID')]
    #[ApiResponse(UserResponse::class)]
    public function getOne(): Response {
        $id = $this->routeParam('id');
        return Response::ok(null);   // throw ApiException::notFound() if missing
    }

    #[Post('/users')]
    #[ApiRequest(CreateUserRequest::class)]
    #[ApiResponse(UserResponse::class, 201)]
    public function create(): Response { return Response::created(null); }
}
```

Route params are extracted from `{name}` placeholders and accessed via `$this->routeParam('name')` or `$this->routeParams()`.

**Routes and swagger spec must be regenerated after any change to controller annotations:**
```
ezmvc generate
```

This writes `generated/routes.php` (PHP blocked by `.htaccess`), `generated/swagger.json` (served to Swagger UI) and `generated/app.css` (the concatenated view stylesheet, also whitelisted).

## CLI Tool (separate package: `ezmvc/cli`)

Install globally:
```
composer global require ezmvc/cli
```

Then from your project root:
```
ezmvc generate                        # build routes + swagger spec + generated/app.css — run after every route or style change
ezmvc scaffold [options]              # generate Entity/Dto + databases/{DbName}Db from existing DB tables
ezmvc verify <EntityName>             # smoke-test a scaffolded Entity+Dto against the real DB, then cleans up
ezmvc swagger:mirror [--version=X]    # fetch the Swagger UI assets into swagger/ to self-host the docs (see below)
ezmvc deploy [options]                # push the project to the target in deploy.json (FTPS, or a local path)
ezmvc new controller      <Name>      # new ApiController with CRUD route stubs
ezmvc new page-controller <Name>      # new page Controller
ezmvc new view  <type> <Name>         # new View + template + stylesheet — type: single|multi|leaf|page
ezmvc new service         <Name>      # new plain Service class (no base class)
ezmvc new exception       <Name>      # new ApiException subclass (HTTP status baked in)
ezmvc new request         <Name>      # new API request body class (Validators\* field validation)
ezmvc new form-request    <Name>      # new form/multipart Request class (page Controllers)
ezmvc new response        <Name>      # new Response class
ezmvc setup                           # create project directories + Config.php template
```

The CLI detects the framework in the current directory or in `vendor/ezmvc/ezmvc/` when installed as a Composer dependency.

**Deployment targets FTP-only shared hosts**, which is why `ezmvc deploy` looks the way it does — files land one at a time, in place, on the live site; there's no atomic release-directory switch the way Deployer/rsync over SSH would give you (the README says so, and points at those tools when the host allows them). Three consequences worth knowing before changing that code:
- **A 503 stub stands in for `index.php` during the upload**, restored last. Since `.htaccess` routes every non-public path through `index.php`, that one file gates the whole app — so the mixed old/new window is invisible to visitors instead of serving half-updated code. A `_ezmvc_maintenance` marker on the target makes an interrupted run detectable, and the next deploy restores `index.php` whether or not it changed. Skipped when `index.php` is excluded from the deploy (nothing would restore it) or on `--no-maintenance`.
- **`vendor/` is skipped on a `composer.lock` hash match, not on mtimes.** A dependency tree is thousands of files at one FTP round trip each, and every `composer install` rewrites their mtimes — mtime-keyed change detection would re-upload the whole tree after any Composer command. `--full` forces it.
- **Deploy state is saved only when every file succeeded** (and the command exits non-zero otherwise). The stored timestamp is what `--changed` compares mtimes against, so recording it after a partial run would permanently hide exactly the files that failed. For the same reason the timestamp is when the run *started*, and the comparison is strictly `<`: a file edited during an upload must still look newer than the deploy.
- **The maintenance stub is also the deploy endpoint** (`--zip`, and the PHP-version probe). This falls out of the routing model rather than being a design flourish: the catch-all rewrites every non-public path to `index.php`, so *any* helper script uploaded beside it never executes — it would have to sit in `public/`, the worst home for something that writes files. The stub is the one thing that is both guaranteed reachable and guaranteed temporary. The endpoint runs a command only when all of: SHA-256 of a per-run 128-bit token matches via `hash_equals`, the request beats a baked-in 30-minute expiry, and the action is known. Everything else — wrong token, no token, expired, unknown action — gets the ordinary 503 maintenance page, never a 401/403 that would confirm an endpoint exists (same reasoning as the uniform 404). After expiry the file cannot be commanded at all, so a crashed deploy degrades to an inert page rather than a live write endpoint. `--zip` additionally refuses a non-https `base_url` (the token would cross the wire in clear), validates every archive entry before writing a byte (no `..`, absolute paths, backslashes, drive letters, or `index.php` — which would overwrite the running stub mid-deploy), and never leaves the payload behind: server deletes it, CLI deletes it again, next run sweeps strays.

## Validators (`core/validators/`, namespace `Validators`)

Validators are `Stringable` regex builders configured through constructor parameters: `new \Validators\Line(minLength: 1, maxLength: 20, optional: true)`. Every validator implements `__toString()`, so an instance drops in anywhere a plain regex string goes — `preg_match()`, for instance — with no explicit cast needed (PHP coerces a `Stringable` to `string` automatically for a typed parameter in this codebase's default weak-typing mode).

Two base classes, one file each: `Validator.php` (abstract — every concrete validator extends this, constructor takes `bool $optional = false`, subclasses implement `body(): string`; `__toString()` wraps it with anchors and, when optional, `|^$` alternation so an empty string also matches — one place handles "optional" for every validator) and `LengthValidator.php extends Validator` (for the subset whose body is one character class repeated across a length range — `Line`, `Text`, `Slug` — subclasses implement `charClass()` instead of `body()` directly).

The 20 concrete classes live in four files grouped by theme, which keeps the pattern definitions easy to scan side by side: `TextValidators.php` (`Line`, `Text`, `Slug`), `NumericValidators.php` (`Integer`, `Decimal`, `Char`, `Boolean`), `DateValidators.php` (`Date`, `Time`, `DateTime`, `Hour`, `Minute`), `IdentityValidators.php` (`Email`, `Phone`, `TaxNumber`, `Hash`, `IdNumber`, `IdText`, `RefreshToken`, `LanguageCode`). Multiple classes per file is safe here specifically because autoloading is classmap-based, not PSR-4 — classmap scans file *contents* for class declarations rather than assuming one-class-per-file-named-after-the-class, so grouping has no autoloading downside.

Variants are parameters rather than separate classes: `Hash` takes a `$length` (default 64, so md5/sha1/sha256 all fit), `Integer` and `Decimal` take `$signed`. They are named `Integer`/`Decimal`, not `Int`/`Float`, because `int`/`float`/`string`/`bool` (and the other scalar type names) are reserved as class names since PHP 7, case-insensitively, in every namespace — same trap as `ReadOnlyField` below.

**Every concrete validator is itself usable directly as a property attribute** — `#[Attribute(Attribute::TARGET_PROPERTY)]` is declared on each one, not just the base classes, because `#[Attribute]` isn't inherited through `extends` (PHP throws "Attempting to use non-attribute class ... as attribute" at `newInstance()` time otherwise). `#[Validators\Line(minLength: 5, maxLength: 20)]` is a real call to `Line`'s constructor, so autocomplete, type-checking, and "unknown parameter name" errors all come from the language itself.

`ApiController::checkRequestAs()` implements this by scanning *every* attribute on a typed property and checking `is_subclass_of($attr->getName(), Validator::class)` — any attribute that resolves to a `Validators\*` class gets `$attr->newInstance()` called on it (PHP resolves the real constructor call, args and all) and the resulting object's `(string)` pattern is matched against the value.

The legacy untyped-property mode (`public $x = Validators\Line::class;`, read by `checkField()`) only supports zero-arg instantiation — a property default value has to be a single compile-time-constant expression, and `Validators\Line::class` (a plain string) has no room to carry constructor args the way an attribute's argument list does. A plain hand-written regex string works there too.

Use `new Validators\Line(...)` directly in any runtime context. `index.php` and `core/swaggergen/Generator.php` both load the whole `core/validators/` directory unconditionally (base classes first, then a glob over the rest) — so every `Validators\*` class is always available to reference by name in a project's own Request/Dto files without an extra manual include, whether or not Composer autoloading is set up.

## Key Utility Classes

| Class | Purpose |
|---|---|
| `Core` | Debug mode, logging facade (`log`, `setLogger`, `setMinimumLevel`), error handling, redirect |
| `Logger` | PSR-3-shaped logging interface — `FileLogger` (default), `StreamLogger`, or any PSR-3 logger |
| `Container` | Optional DI — `addSingleton`/`addScoped`/`addTransient`, explicit registration, `createScope()` — see the Dependency Injection section |
| `Database` / `StaticDatabase` | mysqli connection + parameterized `fetchAll`/`fetchOne`/`fetchColumn`/`execute` and `runTransaction()` — see the Database section |
| `Session` | Secure session wrapper with timeout + client fingerprint validation |
| `Jwt` | JWT create/parse — HS256 (shared secret) and RS256 (RSA keypair, needs `openssl`); the verifier is told the algorithm, never reads it from the token (anti-confusion) |
| `HttpClient` | cURL wrapper for outgoing HTTP calls |
| `SearchCriteria` | WHERE clause builder for Entity::find() |
| `Calendar` | Date/time helpers |
| `Mail` | Email sending — `PhpMail` (built-in `mail()`) by default; `Mail::setSender()` swaps in `new Smtp(...)`, `new SendGrid(...)`, or your own `MailSender` implementation |

## Mail

`PhpMail`, `Smtp` and `SendGrid` all implement `MailSender` directly — no separate adapter classes — as instantiated classes configured through the constructor, since `MailSender::send()` is an instance method (`Mail::setSender()` takes an object, not a class name). `Mail::sender()` returns the installed one, or a `PhpMail` with no default sender address — PHP's `mail()`, the From header left to php.ini's `sendmail_from` — when none was set, the same lazy default `Core::logger()` has. Each backend carries its own default sender and Bcc list, so `setSender()` is the facade's only configuration call; `new PhpMail(Config::MAIL_FROM, $bcc)` gives the `mail()` path an explicit sender. Implementers only need that one method — no `sendTemplate()`/`sendView()`-style convenience methods belong on a transport class; those stay on `Mail` (the facade) alone, which is the only place that needs them.

One `send()` method on `MailSender`, not a `send()`/`sendHtml()` split — `$message` is always HTML. A plain-text message renders identically whether declared `text/plain` or `text/html`, so maintaining two paths (and two methods on every `MailSender` implementation) for that distinction wasn't worth it.

`Mail::send()`/`sendTemplate()`/`sendView()` all accept `string|MailAddress` for `$to`/`$from` — pass a bare email string, or `new MailAddress('jane@example.com', 'Jane Doe')` for a display name. Internally, and in the `MailSender` interface itself, addresses are always a `MailAddress` (`Mail`'s public methods normalize a plain string via `MailAddress::from()` before doing anything else with it) — the string acceptance is purely a public-API convenience, not something `MailSender` implementations need to handle themselves.

**Address lists** (`$to`, and the `PhpMail`/`Smtp` constructors' `$bcc`) are plain arrays of `MailAddress` — no separate "list" type. `MailAddress::listFrom($value)` normalizes a bare string, a single `MailAddress`, or an array of either into a `MailAddress[]`; `MailAddress::joinAll($addresses)` turns such an array into the RFC 5322 comma-joined string a header needs (`"Jane Doe" <jane@x.com>, bob@y.com`). Both are shared by every backend that needs one, rather than each reimplementing the join — `PhpMail` and `Smtp`'s `To:`/`Bcc:` headers use `joinAll()` directly; `Smtp::send()` additionally loops the normalized list to issue one SMTP `rcpt to:` per recipient (a protocol requirement, not just a header). `SendGrid::send()` doesn't need either helper — its API already takes recipients as a native array of `{email, name}` objects, so the normalized list maps straight onto it.

**Attachments**: every send method takes an `array $attachments = []` of `MailAttachment` (`content` — raw, not base64 — `filename`, `mimeType`). `core/mail/MimeBuilder.php` is shared by `PhpMail` and `Smtp`'s stream-based implementation, the two that construct raw messages by hand: `build()` returns the Content-Type and body (the HTML as-is, or multipart/mixed when there are attachments), and `singleLine()` is the CR/LF strip both apply to header values. `SendGrid::send()` maps `MailAttachment` onto the API's own attachment objects instead, since the SendGrid API already handles attachments itself.

A `MailAddress` formats as `"Name" <email>`, or as the bare `email` when it has no name — which is what `PhpMail` puts in the `From:` header.

**`Smtp` transport is TLS-capable (`stream_socket_client`, not `ext/sockets`, which has no TLS wrapper and would send AUTH credentials in the clear).** The transport is inferred from the port unless the constructor's trailing `$secure` argument is given: **465 → `SECURE_SSL`** (implicit TLS from connect, `ssl://`), **587 → `SECURE_TLS`** (plaintext connect, then `STARTTLS` + `stream_socket_enable_crypto()`), **anything else (25 / a local relay) → `SECURE_NONE`** (plaintext). Pass `secure: Smtp::SECURE_NONE` for a non-standard plaintext relay, or `SECURE_SSL`/`SECURE_TLS` to force a mode. Two injection defenses also live here: recipient/from addresses are CRLF-stripped (`MimeBuilder::singleLine()`) so a crafted address can't inject extra `RCPT`/headers, and the body is dot-stuffed (a line starting with `.` → `..`, per RFC 5321) so it can't be truncated or smuggle commands past the `\r\n.\r\n` terminator. `PhpMail` applies the same CRLF strip to every header value — `To`/`From`/`Reply-to`/`Bcc` and the subject — so a crafted address or subject can't inject headers there either. (The message body is deliberately left untouched — newlines there are legitimate content.)

**`Smtp::send()` returns `true` only when the server accepted the message**: `sendMail()` checks the final reply — the one to the DATA payload, not an earlier EHLO/AUTH/RCPT step — for a `2xx` code.

No `cc` support anywhere — `MailSender` has no cc concept. Bcc is set through the `PhpMail`/`Smtp` constructor, or `SendGrid::addBcc()`/`clearBcc()`.

## Configuration

All app config lives in `Config.php` as class constants. `app.php` is the place to wire up databases, set auth defaults, initialize mail, and include model/view files. `Config.php` is not committed with real credentials — keep secrets out of version control.

**Avoiding framework fingerprinting.** A client shouldn't be able to tell the stack from the outside. The one framework signature that reaches the browser is the **session cookie name** — so it's driven by `Config::SESSION_NAME` (read in `index.php` and passed to `Session::init()`), defaulting to a generic `'sid'` rather than anything that names the framework or PHP (`'ezmvc'`, `'PHPSESSID'`). Set your own per project. Everything else with an `ezmvc` string is server-internal (the `EZMVC` bootstrap constant, the `.ezmvc` marker — which `.htaccess` 404s — comments/docblocks) and never leaves the server; no `X-Powered-By`/framework response header is emitted. The other build output a browser fetches, `generated/app.css`, is comment-stripped for the same reason: a fixed scaffolder comment repeated in every project's stylesheet would be a far stronger signature than any header, since it is greppable across the whole web. The built-in welcome page, served at the root while no default page controller is set, lists the PHP version, so it appears only when `Config::ENABLE_WELCOME_PAGE` is true (the sample's value for development); a Config without the constant gets the ordinary 404.

**Default security headers.** `index.php` sends `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN` and `Content-Security-Policy: frame-ancestors 'self'` on every response, before `app.php` loads, so a project overrides or drops them with `header()`/`header_remove()`. The Swagger page, which Apache serves directly, sends the same three itself.

## Logging & Debug

Every entry has a **PSR-3 level** (`LogLevel::DEBUG` … `LogLevel::EMERGENCY`) and a **category** naming where it comes from (`REQ`, `HttpClient`, `Checkout`, …). Log statically with `Core::log($entry, LogLevel::WARNING, 'Checkout', ['sku' => $sku])` (level defaults to `info`, category to `app`), or through the logger object: `Core::logger()->warning('Stock low', ['category' => 'Checkout', 'sku' => $sku])`. The category rides in `$context['category']`; the rest of the context is appended as JSON, with a `Throwable` under any key rendered as `Class: message @ file:line`.

**The logger is swappable.** `Logger` (`core/logging/Logger.php`) is an interface with psr/log 3's exact method signatures. Its built-in implementations share `LineLogger`, which formats every entry as one line and drops the ones below the minimum level:
- `FileLogger` — the default. Appends to a daily `<prefix>_Y-m-d.log`, locking each write so concurrent requests can't interleave lines. The prefix is `Config::LOGFILE_PREFIX`; relative values (the sample's `'logs/log'`) resolve against the working directory, which is the project root under `index.php`; an empty constant means `logs/log` under the project root. The directory is not created — it must exist, or the write fails silently; `logs/` ships in the repository, held there by its `.htaccess`.
- `StreamLogger` — writes to `php://stderr` (default) or any stream: for CLI/cron entry points and for hosts that collect process output (Docker, PaaS).

Any PSR-3 v3 logger class (Monolog, …) becomes one with `class AppLogger extends ThatLogger implements \EzMvc\Logger {}`. Install a logger with `Core::setLogger()` **first thing in `app.php`**, or in an entry point after it loads `app.php`; environments differ through `Config.local.php`, which `index.php` loads in place of `Config.php` when present. A `Logger` type-hint receives that same object with no registration — see "Logger and MailSender belong to the core" in the Dependency Injection section.

**The minimum level** — `Core::setMinimumLevel(LogLevel::WARNING)` — is enforced by `LineLogger`; a plugged-in PSR-3 logger filters by its own configuration. Until it is set it follows debug mode: `debug` with `Core::setDebug()`, `info` otherwise. `Core::isLogged($level)` answers whether an entry would pass, so an expensive entry isn't built for nothing — the request/response bodies are read only when `debug` passes. Unknown level strings (e.g. `'ERROR'`) throw `InvalidArgumentException`. `Core::setDebug()` also shows PHP errors inline.

**Every request is logged, in production too, as a pair of lines sharing a request id.** `Core::init()` (the first thing `index.php` runs, before `app.php`) gives the request an 8-hex-digit id and queues the `REQ` line — method and path; `index.php` writes it right after `app.php` (`Core::logRequest()`), so it reaches the logger `app.php` chose, and any earlier `Core::log()` writes it first, so `REQ` stays the first line even when `app.php` logs or dies. A shutdown function writes the `RES` line — status and duration in ms — after `errorHandler()`, so it covers every exit: an API response, a page, a 404, a redirect, a `die()`, a fatal error. **A `REQ` with no matching `RES` is a request the process never finished** (killed, crashed PHP itself); `grep <id> logs/log_*.log` shows one request's whole story. Every API error body (`ErrorResponse`) carries the id as `requestId`, in production too, so a client's bug report points straight at its log lines. Every line carries the id (`Core::requestId()`), and every entry is one line: newlines become spaces, so an entry can't forge another line; arrays/objects are logged as compact JSON.

```
2026.09.27. 10:40:57 [::1] [6225bf60] [info] [REQ]: GET /log/boom
2026.09.27. 10:40:57 [::1] [6225bf60] [error] [Dispatch]: RuntimeException: database went away @ …LogController.php:13
2026.09.27. 10:40:57 [::1] [6225bf60] [info] [RES]: 500 10ms
```

At `debug` the bodies follow as `REQ-BODY` (under 4 KB) and `RES-BODY` (API responses). `Core::redactSecrets()` masks them, and the path's query string, with a plain regex pass: any JSON key or form/query field whose name contains `password`, `passwd`, `secret`, `token`, `apikey`, `api_key` or `csrf` (the `Core::SECRET_KEYS` list) gets `***` as its value, at any depth. Unexpected errors in dispatch are logged at `error` under `Dispatch` with class, message and location — the client only ever sees the generic 500 in production; a fatal PHP error is `critical` under `PHP`. `errorHandler()` reacts only to fatal error types: `error_get_last()` also returns a mere warning, even an `@`-suppressed one like `@filemtime()`, which must not become an error line or a 500.

## Swagger / API Docs

`/swagger/` contains Swagger UI. The API spec is generated to `generated/swagger.json` by `ezmvc generate` (which calls `swaggergen\Generator::build()`); `swagger/swagger-initializer.js` points the UI at it.

**The Swagger UI front-end assets are the only third-party code in the framework, and they are deliberately *not* committed.** `swagger/` holds only the framework's own files (`index.php`, `index.html.php`, `index.css`, `swagger-initializer.js`) plus a one-line `swagger/swagger-ui.version` pinning the Swagger UI release. The `swagger-ui-*.js`/`.css` bundles are `.gitignore`d and acquired on demand, so a clone carries zero vendored bytes. `swagger/index.html.php` decides at request time: if the mirrored bundles are present it serves them locally (`./`), otherwise it falls back to the pinned jsDelivr CDN (`swagger-ui-dist@<version>`) so the docs still render on a fresh clone with no setup. The CDN fallback carries Subresource Integrity: `swagger/swagger-ui.integrity.json` (committed) records the version and a SHA-384 hash per script/stylesheet, the page adds `integrity`/`crossorigin` attributes from it, and when its version doesn't match `swagger-ui.version` the page answers 503 "run `ezmvc swagger:mirror`" rather than load unverified script onto the app's origin. `ezmvc swagger:mirror` downloads the referenced assets (the `swagger-ui.css`/`swagger-ui-bundle.js`/`swagger-ui-standalone-preset.js` bundles plus the two favicons) from that CDN into `swagger/` — reading the version from `swagger/swagger-ui.version`, or `--version=X.Y.Z` to override. Run it to self-host the docs (no runtime CDN dependency); since the files are real once mirrored and aren't in `deploy`'s exclude list, they ship with a deploy. It also rewrites `swagger-ui.integrity.json` from the files it downloaded. Bump the version file and re-run to upgrade, then commit both files.

The generator only emits schemas for DTOs actually reachable from an endpoint — it scans `dtos/`/`requests/`/`responses/` to know what exists, but a class only makes it into `components.schemas` if some controller method references it via `#[ApiResponse]`/`#[ApiRequest]`, or is reachable from one transitively through another referenced DTO's typed properties (e.g. a composite Response holding another entity's Dto). It does **not** dump every entity/dto in the project into the public spec just because the class exists — a table with no API endpoint stays invisible to Swagger. (Transitive following only works for typed properties; a DTO that references another solely via an `@property` docblock tag isn't followed.)

**`ErrorResponse` is the one exception to "only what's referenced":** since the `ApiController` boundary maps *every* error onto it (see the Services section), the generator always registers its schema (`core/ErrorResponse.php`, reflected like a DTO) and attaches a `default` response pointing at it to **every** operation. So each endpoint documents its success response(s) plus a `default` = `ErrorResponse` for any error status — matching the uniform runtime behavior, with no per-method `#[ApiResponse]` needed for errors.

## Known Gotchas

Non-obvious traps that surfaced while building or extending this framework — the kind you only discover by hitting them, not by reading the code. Add to this list whenever a new one turns up.

- **Don't redeclare the `static`-returning Entity methods in a subclass.** `get()`, `findFirst()`, `fill()`, `build()`, `getById()` and `findOne()` all return `?static`/`static` so that `$user = (new User())->get(1)` is typed `User` rather than `Entity`, giving property autocomplete without a `/* @var */` line. The cost is an LSP constraint: `static` is PHP's *narrowest* return type, so a subclass redeclaring one of these with `?self` — which looks narrower but is wider — is a fatal "Declaration must be compatible" error the moment that class is **linked** — for an autoloaded class, the first time anything references it at all (a static call, `instanceof`, `class_exists`, not just `new`); for a class declared in an already-loaded file, at compile time before any of that file's code runs. Narrowing a parameter (`int $id` instead of `mixed`) fails the same way, as does dropping the return type entirely. Don't override them at all; late static binding already returns the right class. `find()`/`findAll()` are the exception — PHP has no typed arrays, so they're plain `array` with a `@return static[]` docblock, and both return an empty array rather than null when nothing matches.
- **`readonly` is a reserved word since PHP 8.1.** You cannot name a class `ReadOnly` (or any case-variant) — PHP rejects it regardless of case, the same way it blocks `list` or `array` as class names. This is exactly the name someone would reach for when adding a "this property is read-only" attribute; it's why this framework's attribute is called `ReadOnlyField` instead.
- **`#[\SensitiveParameter]` needs its leading backslash.** Every parameter carrying a secret (DB/SMTP passwords, API keys, JWT keys and tokens, controller secrets) has it, so a stack trace shows `Object(SensitiveParameterValue)` instead of the value — which matters because the debug error screen and `ErrorResponse` print `getTraceAsString()`. In a file under `namespace EzMvc`, a bare `#[SensitiveParameter]` resolves to `EzMvc\SensitiveParameter`, and since an attribute class is only looked up when something reflects it, PHP accepts that silently and redacts nothing. The attribute takes effect from PHP 8.2; 8.0 and 8.1 parse it and ignore it.
- **PHP's octal-escape gotcha in double-quoted strings.** `"\3..."` inside a double-quoted string is not literal backslash-then-3 — PHP reads `\` followed by 1–3 octal digits as an escape sequence and silently produces a control character instead, corrupting anything after it (a session ID, a path, a UUID fragment). Trivial to avoid once known (single-quote the string, or use forward slashes for paths), invisible until it bites.
