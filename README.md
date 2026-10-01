# Nexus

Nexus is a multi-user, remote MCP gateway. You sign in with GitHub, connect each MCP server once (GitHub, Notion, Linear or any remote server), and bundle those Connections into **Stars**: MCP endpoints you add to Claude Code, claude.ai, Codex, Cursor and Grok. Every tool and prompt in a Star has its own on/off switch, so changing what an AI client may do happens in Nexus, not in each client's config file.

The product spec lives in [issue #1](https://github.com/princejohnsantillan/nexus/issues/1).

## Stack

- Laravel 13 (from Laravel's blank Livewire starter kit) on PHP 8.4
- Livewire 4 multi-file components and [Flux UI](https://fluxui.dev) (free tier, default theme) on Tailwind CSS 4
- Pest 5, Larastan at level max, Rector and Pint, all run by `composer qa`
- Laravel Boost guidelines and skills for Claude Code, Codex, Cursor and Grok
- SQLite locally; Laravel Cloud (Serverless Postgres, Valkey, managed queue) in production

## Local setup on Herd

You need PHP 8.4, Composer 2, Node 22 and [Laravel Herd](https://herd.laravel.com).

```bash
git clone --branch stars https://github.com/princejohnsantillan/nexus.git
cd nexus
composer setup
herd link nexus
herd secure nexus
```

The rewrite lives on the `stars` branch until it replaces `main`, which still holds the first version of Nexus.

`composer setup` installs the PHP and Node dependencies, creates `.env` from `.env.example`, generates the app key, creates the SQLite database at `database/database.sqlite`, runs the migrations and builds the front end.

Then generate a master key for credential encryption and put the line it prints in place of the empty `NEXUS_MASTER_KEY=` in `.env`:

```bash
php artisan nexus:master-key
```

Open <https://nexus.test>. If you link the site under another name (for example `herd link nexus-t2` in a git worktree), set `APP_URL` in `.env` to match, e.g. `APP_URL=https://nexus-t2.test`.

Herd serves the site, so there is nothing to start. While working on the front end, run `npm run dev` for hot reloading. `composer dev` runs everything at once: Vite, a queue worker, the log viewer and a spare `php artisan serve`.

### Signing in

People sign in with GitHub; Nexus stores no passwords. To use GitHub sign-in, register an OAuth app at <https://github.com/settings/developers> with the callback URL `{APP_URL}/auth/github/callback` (e.g. `https://nexus.test/auth/github/callback`) and set `GITHUB_CLIENT_ID` and `GITHUB_CLIENT_SECRET` in `.env`.

Locally you can skip the OAuth app with the dev sign-in. Set `NEXUS_DEV_SIGN_IN=true` in `.env` and seed the two dev users:

```bash
php artisan db:seed
```

The welcome page then offers "Sign in as Dev User" and "Sign in as Second User". The same links work from the address bar or curl: `/dev/sign-in/dev` and `/dev/sign-in/second`. The dev sign-in only exists when `APP_ENV=local` and the flag is on; everywhere else it is a 404. Seeding again restores a deleted dev user.

## The quality gate

```bash
composer qa    # check everything, change nothing (this is what CI runs)
composer fix   # apply Rector, then Pint
```

`composer qa` runs these in order and stops at the first failure:

1. `rector process --dry-run`: Rector ([`rector.php`](rector.php)) with the PHP 8.4 set, the composer-based Laravel sets, and the dead code, code quality and type declaration sets.
2. `pint --test`: Pint ([`pint.json`](pint.json)) with the Laravel preset plus `declare_strict_types`, so every PHP file declares strict types.
3. `phpstan analyse`: Larastan ([`phpstan.neon`](phpstan.neon)) at level max with no baseline, over `app`, `bootstrap`, `config`, `database`, `routes` and the component PHP files under `resources/views` (Blade files are excluded). The `calebdw/larastan-livewire` extension understands anonymous component classes and `#[Computed]` properties.
4. `pest --parallel`: the whole test suite.

Run part of the suite while you work, then `composer qa` before you push:

```bash
vendor/bin/pest tests/Feature/StarsPageTest.php
vendor/bin/pest --filter="empty state"
```

Don't silence a failure with a baseline, an ignore comment or a cast: fix the cause.

### IDE helpers

```bash
composer ide-helper
```

This regenerates `_ide_helper.php` and `.phpstorm.meta.php`, which are git-ignored, and writes model docblocks into the model classes, which are committed. Run it after changing a model or a migration, then `composer fix`.

## Conventions

### Screens are Livewire multi-file page components

Every screen is a Livewire 4 multi-file component under `resources/views/pages`, with one folder per screen holding its PHP class file and its Blade view:

```text
resources/views/pages/stars/index/
├── index.php          # return new #[Title('Stars')] class extends Component { … };
└── index.blade.php
```

Generate them, don't hand-write them:

```bash
php artisan make:livewire pages::stars.show
```

[`config/livewire.php`](config/livewire.php) makes multi-file the default type, turns the ⚡ emoji in folder names off and turns colocated test files off. The published stubs in [`stubs/`](stubs) add `declare(strict_types=1)`, `return new class` and a trailing newline, so a freshly generated component passes `composer qa` as generated.

Route pages with Livewire's page routing and name every route `area.screen`:

```php
Route::livewire('/stars', 'pages::stars.index')->name('stars.index');
```

Set the page title with `#[Title('…')]` on the class. Pages use the app layout by default.

**Components stay thin.** A component holds only screen state and calls named classes under `App\` (actions, services, models) that do the real work: connecting, refreshing, proxying, encrypting, authorising. That keeps business logic where the architecture tests and PHPStan can see it.

### Layouts and shared Blade components

- `layouts::app` ([`resources/views/layouts/app.blade.php`](resources/views/layouts/app.blade.php)) is the signed-in app: a Flux sidebar with Stars, Connections and Activity that collapses into a menu on small screens, and a profile menu with the appearance switch. It is the default page layout.
- `layouts::public` is for public pages such as the welcome page. Choose it with `#[Layout('layouts::public')]`.
- Both include [`partials/head.blade.php`](resources/views/partials/head.blade.php), which loads the Inter font, the Vite assets and `@fluxAppearance`; both end with `@fluxScripts`.
- Shared pieces live in `resources/views/components`: `<x-empty-state>` (every list needs a helpful empty state), `<x-appearance-switch>`, `<x-app-logo>`, `<x-icons.github>`, `<x-connection-header>` (a Connection's logo, name, status and row of sub-page links), `<x-connection-status>`, `<x-tool-hint>` (one hint as yes, no or not stated), `<x-tool-hints>` (the hints a tool declared true, as badges), `<x-star-header>` (a Star's name, description and row of sub-page links), `<x-connection-picker>` (checkbox cards for choosing a Star's Connections; bind it like a checkbox group) and `<x-connector-logo>` (a service's logo on a tile: `:connector="$connection->connector()"`, `size="sm"` for lists and pickers; a server icon for a custom server).
- Route parameters for records resolve only within the signed-in user's own data (`bindOwnRecords()` in `AppServiceProvider`), so another user's Connection is a 404, never a 403. Bind each new record type there the same way.
- Every page except the welcome page requires sign-in: add app routes inside the `auth` group in [`routes/web.php`](routes/web.php). Guests are sent to the welcome page, and signed-in visitors to the welcome page go to the app.
- Toasts: in a Livewire action call `Flux::toast(...)`. To show one after a redirect, flash `toast` with its text and a variant (`success`, `warning` or `danger`): `to_route('home')->with('toast', ['variant' => 'success', 'text' => __('Saved.')])`. Both layouts render it with `<x-flash-toast>`.

### UI with Flux free

Use only Flux's free components (layouts, navlist and navbar, button, input, textarea, native select, checkbox, radio, switch, field, heading and text, badge, callout, card, table, pagination, modal, toast, dropdown and menu, tooltip, avatar, profile, separator, icon, brand) with the default theme. Pro components (tabs, accordion, popover, command, autocomplete, searchable or multiple select, date picker, chart) are not available. Icons are [Heroicons](https://heroicons.com) by name, e.g. `<flux:icon.star />`. Dark mode follows Flux's appearance setting (light, dark or system), stored in the browser.

Flux's switch keeps its own on/off state once drawn, and doesn't follow a changed `checked` attribute when Livewire updates the page. When the server decides a switch's state (as on a Star's Tools page, where one click can change many), key it by that state, e.g. `wire:key="switch-{{ $id }}-{{ $on ? 'on' : 'off' }}"`, so a changed switch is drawn afresh.

### Tests

Tests are written with Pest 5:

- `tests/Feature`: tests through a public seam (HTTP requests, or a Livewire page tested by name with `Livewire::test('pages::stars.index')`). They run on the application's `Tests\TestCase` with `RefreshDatabase` against in-memory SQLite.
- `tests/Unit`: small tests for deep, pure modules only.
- `tests/Arch`: the architecture rules and the component scan.

`Tests\TestCase` calls `Http::preventStrayRequests()` and `withoutVite()`, so a test never reaches the network and doesn't need a front-end build. Fake the network edge (`Http::fake()`, Socialite fakes); don't mock our own classes.

Faked requests pass through the [outbound guard](#outbound-requests) too, so fake `https://` URLs. `Tests\TestCase` fakes the guard's DNS so that every host resolves to a public address. To make a host resolve somewhere else, call `$this->fakeDns(['internal.example.com' => ['10.0.0.1']])`; an empty list makes the host unresolvable.

#### The fake MCP server

[`Tests\Support\FakeMcpServer`](tests/Support/FakeMcpServer.php) stands in for a Connection's remote MCP server. It answers one URL through `Http::fake()`, so the real downstream client, transport and outbound guard all run:

```php
$server = FakeMcpServer::at('https://mcp.example.com/mcp')              // the default URL
    ->withTools('[{"name":"search","inputSchema":{"type":"object","properties":{}}}]')
    ->onCall('search', fn (stdClass $arguments): array => ['content' => [['type' => 'text', 'text' => 'Found it']]]);
```

Out of the box it is a 2025-11-25 server, like most today: it answers `server/discover` with "method not found", then `initialize`, and lists no tools. Script the rest:

- `withTools()` takes tool definitions as arrays or as a JSON string; the string is sent exactly as written, so use it when `{}` or long numbers must survive. `paginate(2)` serves them two to a page, with cursors.
- `onCall()` answers `tools/call` for a tool with a result (an array, or its raw JSON). Unknown tools get a JSON-RPC "invalid params" error.
- `speaking('2026-07-28')` answers `server/discover`; `speaking('2025-06-18')` or `'2025-03-26'` answers `initialize` with that version.
- `requireHeader('Authorization', 'Bearer …')` refuses other requests with a 401 (or the status you pass) and the `WWW-Authenticate` header set by `challengingWith()`.
- `streaming()` answers with server-sent events instead of plain JSON; `streaming(splitData: true)` spreads each message over several `data:` lines, with CRLF line ends, a comment and an id.
- `respondTo($method, $responder)` replaces the answer to one method. The responders are `FakeMcpServer::error()` (a JSON-RPC error), `errorWithId()` (one carrying a placeholder or another request's id), `httpStatus()`, `raw()` (any body, e.g. malformed JSON), `timeout()` and `unreachable()`.
- `beforeAnswering($method, $callback)` runs the callback while that request is in flight, to play out a race such as the Connection being deleted or moved mid-refresh.

Afterwards, `received('tools/call')` returns the JSON-RPC messages it got, decoded with objects kept as objects, `requests()` the HTTP requests (for headers), and `transferOptions()` the HTTP client's options for each one, such as its timeouts. Anything sent to another URL is a stray request and fails the test.

### Outbound requests

Every request through Laravel's HTTP client goes through the outbound guard in [`app/Outbound`](app/Outbound). The URL must use HTTPS and its host must resolve only to public addresses. The request is then pinned to those addresses with curl's resolve option and always connects directly, ignoring any proxy from the environment or the request options. It is never streamed, and redirects are never followed. A refused request throws `App\Exceptions\OutboundRequestBlocked`, whose message is safe to show the user. To validate a URL before saving it, call `OutboundGuard::check()`. `NEXUS_BLOCK_PRIVATE_NETWORKS` and `NEXUS_REQUIRE_HTTPS` can turn the checks off only when `APP_ENV=local`.

### Downstream MCP servers

[`App\Downstream\DownstreamClient`](app/Downstream/DownstreamClient.php) is the only way Nexus talks to a Connection's MCP server. It opens a session signed in the way the Connection says (no auth, or its header) and connects on the first request:

```php
$session = $downstream->session($connection);

$tools = $session->listTools();                                 // list<string>: each tool's JSON, every page
$result = $session->callTool('search', '{"query":"laravel"}');  // JSON object in, JSON object out
```

- Tools and results come back as the exact JSON text the server sent, and a tool call's arguments go out as the exact JSON object given. Nothing is decoded and encoded on the way, which would turn `{}` into `[]`, round long numbers and fail on ones like `1e400`. Decode a copy with `json_decode()` to read it; keep the text to store or forward it. `App\Downstream\RawJson` cuts members and elements out of JSON text without decoding them.
- It speaks 2026-07-28 (`server/discover`) where the server does, and otherwise falls back to `initialize`, accepting servers that settle on 2025-11-25, 2025-06-18 or 2025-03-26. It follows `tools/list` cursors, pairs errors that come back with a placeholder or mismatched id with their request, and reads server-sent events as the SSE format defines them (an event's `data:` lines joined).
- `NEXUS_DOWNSTREAM_CONNECT_TIMEOUT` (10 s) limits connecting and the handshake; `NEXUS_DOWNSTREAM_CALL_TIMEOUT` (55 s, under Laravel Cloud's 60-second request limit) limits listing and calling tools.
- Every failure throws `App\Exceptions\DownstreamRequestFailed`. Its `failure` (`App\Enums\DownstreamFailure`) says why: `NeedsSignIn` (401, 403 or an invalid-token challenge, whose `challenge` the exception keeps), `Timeout`, `Unreachable` (connection failed, blocked by the outbound guard, 404 or 5xx), `ProtocolError` or `ToolError` (a JSON-RPC error instead of a tool result). Its message is Nexus's own and safe to show; it never contains text from the server, and no exception from the server is chained to it. A tool result with `isError: true` is a result, not a failure.

The pieces behind it (the transport, the lenient protocol, the raw request) live in `App\Downstream` too; reach them only through the client.

### Connections and their catalogs

A Connection (`App\Models\Connection`) is one of a user's accounts on a remote MCP server. Its handle prefixes its tools' names in Stars, so it never changes once created: the model refuses to save a changed handle. A header sign-in keeps the header's name in `settings` and its value encrypted in `secrets`. `nexus.limits.connections_per_user` (`NEXUS_CONNECTIONS_PER_USER`, 25) caps how many a user may have. Save every new Connection through `App\Actions\SaveNewConnection`: it holds a per-user cache lock while it counts and inserts, so two adds at once can't both slip under the limit, and its `limitMessage()` is what the user is told. Load tools after it returns, outside the lock.

A Connection's catalog is its stored copy of the server's tools (`App\Models\ConnectionTool`). [`App\Actions\RefreshCatalog`](app/Actions/RefreshCatalog.php) re-reads it:

```php
$loaded = $refreshCatalog->handle($connection);  // bool
```

It matches tools by name, rewrites only the ones whose definition hash changed, removes vanished ones, skips names the MCP specification doesn't allow, stores each definition as the exact JSON received with its four behaviour hints (null when the server didn't state one), and marks the Connection connected. When the server can't be listed, the previous catalog stays and the Connection's status (`needs_auth` or `error`) and last error say why; nothing is logged.

Asking the server takes time, so the outcome is written in one transaction holding the Connection's row, and only if the Connection still exists with the same URL, sign-in method, settings and credentials (the stored ciphertext, which changes with every new value); a refresh overtaken by a delete, a new server or a replaced header is dropped, whether it succeeded or failed. A database error while storing is reported as `CatalogNotStored`, which names the Connection and the SQLSTATE but none of the values (they came from the server), and recorded on the Connection under the same check. Adding a Connection and its "Refresh tools" button run it straight away; changing a Connection's URL clears its stored credentials and its catalog first.

### Stars and their tools

A Star (`App\Models\Star`) is one MCP server endpoint owned by a user, bundling some of their Connections. Its URLs use its `public_id`, 20 random lowercase letters and digits (`/stars/{public_id}`, and `/mcp/{public_id}` for clients), never its numeric id, so they stay the same when it is renamed. Its `slug` comes from its name when it is created ("work", then "work-2" for the user's next "Work"), is unique among the user's Stars, names it in client configuration, and is kept when the Star is renamed. `nexus.limits.stars_per_user` (`NEXUS_STARS_PER_USER`, 10) caps how many a user may have.

Change Stars through the actions, which keep their rules:

- `App\Actions\CreateStar` holds a per-user cache lock while it counts and inserts, like `SaveNewConnection`; `limitMessage()` is what the user is told.
- `App\Actions\UpdateStarConnections` sets which Connections a Star includes, ignoring anyone else's. A Connection taken out loses its switches in that Star. Deleting a Connection removes it, and its switches, from every Star.
- `App\Actions\SwitchStarTools` gives tools of one of the Star's Connections the user's own switch (on or off), or takes it away (null). Given tool names it changes only those; without, it changes the whole Connection and drops switches for tools its server no longer lists.

[`App\Stars\StarToolset`](app/Stars/StarToolset.php) is how anything reads a Star's tools:

```php
$toolset->tools($star);                                     // list<StarTool>: every tool of its Connections, on or off
$toolset->enabledTools($star);                              // list<StarTool>: only the ones that are on
$toolset->enabledTool($star, 'deepwiki__ask_wiki_question'); // ?StarTool: the tool with this exposed name, or null when it is unknown or off
```

A `StarTool` holds the Connection, its catalog entry (`ConnectionTool`), the exposed name `{handle}__{tool}`, whether it is `enabled`, and the user's own `switch` (null when the policy decides). `definition()` is the tool's JSON exactly as the server sent it, schemas and annotations untouched, with the exposed name in place of the server's.

A tool is on when the user switched it on in that Star and off when they switched it off. Otherwise the Star's new-tool policy (`App\Enums\NewToolPolicy`) decides, by the tool's annotations at the last refresh: `read_only` (the default) turns on only tools whose server declares `readOnlyHint: true`, so a tool that stops being read-only stops being on; `all` turns every tool on; `none` turns every tool off. Switches are stored by tool name, not catalog row, so they survive catalog refreshes, even a tool disappearing and coming back.

### Connectors

The Add connection page is a gallery of **connectors**: services with an official remote MCP server, such as GitHub, Notion and Linear. [`App\Connectors\ConnectorCatalog`](app/Connectors/ConnectorCatalog.php) loads them from [`resources/connectors`](resources/connectors), and a Connection made from one keeps its key in `connector_key` (`$connection->connector()` returns it; custom servers have none).

To add a connector, add two files, named after its key (lowercase letters, digits and dashes, at most 20 characters, since the key is also the handle suggested for its first Connection):

1. `resources/connectors/{key}.json`, its definition:

    ```json
    {
        "name": "Sentry",
        "summary": "Issues, events and releases from your organization.",
        "url": "https://mcp.sentry.dev/mcp",
        "docs_url": "https://docs.sentry.io/product/sentry-mcp/",
        "registration": "automatic"
    }
    ```

    - Required: `name`, a one-line `summary`, the server's HTTPS `url`, a `docs_url`, and `registration`: `automatic` when the server lets clients register themselves, `pre_registered` when it only accepts OAuth apps registered in its developer console.
    - Optional: `scopes` (a list; without it the server's challenge decides), `preview` (`true` for servers still in preview), `requires_deployment_app` (`true` when users can't bring their own OAuth app, so the connector is only available once the deployment has one), `app` (required for `pre_registered`: `console_url` where users register an app, `instructions`, and an optional `manifest`), and `token` for services that accept a token users create themselves (`console_url` where they create one, `instructions`, and optionally `header_name`, default `Authorization`, and `value_prefix`, default `"Bearer "`).
    - Unknown fields are rejected, so a typo fails loudly, and credentials can't be added: the deployment's OAuth app for a connector comes from `NEXUS_{KEY}_CLIENT_ID` and `NEXUS_{KEY}_CLIENT_SECRET` (dashes in the key become underscores), which `config/nexus.php` reads for every JSON file. List the pair in `.env.example` for a connector whose server only accepts registered apps, as GitHub's is.

2. `resources/connectors/logos/{key}.svg`, the service's official logo from its brand assets: one `<svg>` element with a `viewBox` and no `width`, `height`, `class` or `style`, cleaned of metadata. It is inlined into pages, so it must not contain scripts, styles, event handlers, links or anything external. Use `fill="currentColor"` for a monochrome mark so it follows the text colour in dark mode, as GitHub's and Linear's do.

[`tests/Feature/Connectors/ConnectorCatalogTest.php`](tests/Feature/Connectors/ConnectorCatalogTest.php) validates every shipped definition and logo, so run it after adding one. The gallery's trademark notice names every connector automatically.

A user connects a gallery service with the methods its definition allows and this Nexus supports. For a token, [`App\Actions\ConnectWithToken`](app/Actions/ConnectWithToken.php) saves a header Connection to the connector's server through `SaveNewConnection`, with the token after its value prefix (so `Authorization: Bearer …`) stored encrypted, then loads its tools; if the server refuses the token, the Connection is removed and the user is told at once. OAuth sign-in isn't built yet, so connectors that only sign in with OAuth (Notion, Linear) show as not available yet, with the reason.

### Architecture rules and banned functions

[`tests/Arch/ArchitectureTest.php`](tests/Arch/ArchitectureTest.php) applies Pest's Laravel and security presets, a "no debug calls" rule and strict types for `App` and `Database`. Architecture rules can't see the anonymous classes in component files, so [`tests/Arch/ComponentScanTest.php`](tests/Arch/ComponentScanTest.php) scans every component PHP file for calls to the same banned functions (debug helpers, `env`, `eval`, `exec` and friends, `unserialize`, `extract` and the rest), including calls through a `use function` import or alias, and proves the scan catches a `dd()`. Like the architecture rules, it can't see dynamic calls such as `$function()` or string callables. The lists live in [`tests/Support/BannedFunctions.php`](tests/Support/BannedFunctions.php).

### Configuration and secrets

The repository is public. Never commit `.env`, databases, keys or tokens; `.gitignore` excludes them. Nexus's own settings use the `NEXUS_` prefix, and each feature documents its variables in `.env.example` in the same change that starts reading them.

### Credential encryption

Every credential Nexus stores is encrypted with AES-256-GCM under its owner's own data key (`App\Encryption`). Data keys live in the `data_keys` table, wrapped by the master key in `NEXUS_MASTER_KEY`, so the database alone reveals nothing. Deleting an account permanently removes its data key and ciphertext from the live database. Database backups still hold both, so a restored backup can be decrypted again with the master key; backups age out under the database's backup retention. True crypto-shredding of backups needs an external key store (KMS), which comes later. Without a valid master key Nexus throws a `MasterKeyException` rather than encrypt or decrypt anything. Tests get a fresh master key from `Tests\TestCase`.

A model keeps its secrets in a `secrets` column cast with `AsEncryptedSecrets`, owned by its `user_id`. Hide the column and never make it fillable. Callers never see ciphertext:

```php
$connection->secrets->get('access_token');
$connection->secrets->put(['access_token' => $token, 'refresh_token' => null]); // null removes a secret
$connection->save();
```

Objects that hold plaintext secrets or keys (`Secrets`, `DataKeys`, `SecretCipher`, `LocalKeyWrapper`) use `App\Concerns\KeepsSecretsInMemory`: serializing one throws, so put the model in a queued job, never its secrets (a model serializes only ciphertext), and dumps show `[redacted]`. Mark parameters that carry secrets `#[\SensitiveParameter]`, and never let an exception from a native function that received a secret (such as `json_encode()`) escape: its trace keeps the arguments.

### AI agents

Laravel Boost writes guidelines (`CLAUDE.md`, `AGENTS.md`), skills (`.claude/skills`, `.agents/skills`, `.cursor/skills`, `.grok/skills`) and the Boost MCP server config (`.mcp.json`, `.codex/config.toml`, `.cursor/mcp.json`, `.grok/config.toml`) for Claude Code, Codex, Cursor and Grok. [`boost.json`](boost.json) records that choice. Project-specific guidance lives in [`.ai/guidelines`](.ai/guidelines). After installing a package or editing a guideline, regenerate everything with:

```bash
php artisan boost:update
```

## Continuous integration

[`.github/workflows/qa.yml`](.github/workflows/qa.yml) runs on every push and pull request targeting `stars`: it installs the PHP and Node dependencies, builds the front end and runs `composer qa` on PHP 8.4.
