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

Open <https://nexus.test>. If you link the site under another name (for example `herd link nexus-t2` in a git worktree), set `APP_URL` in `.env` to match, e.g. `APP_URL=https://nexus-t2.test`.

Herd serves the site, so there is nothing to start. While working on the front end, run `npm run dev` for hot reloading. `composer dev` runs everything at once: Vite, a queue worker, the log viewer and a spare `php artisan serve`.

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
- Shared pieces live in `resources/views/components`: `<x-empty-state>` (every list needs a helpful empty state), `<x-appearance-switch>`, `<x-app-logo>` and `<x-icons.github>`.

### UI with Flux free

Use only Flux's free components (layouts, navlist and navbar, button, input, textarea, native select, checkbox, radio, switch, field, heading and text, badge, callout, card, table, pagination, modal, toast, dropdown and menu, tooltip, avatar, profile, separator, icon, brand) with the default theme. Pro components (tabs, accordion, popover, command, autocomplete, searchable or multiple select, date picker, chart) are not available. Icons are [Heroicons](https://heroicons.com) by name, e.g. `<flux:icon.star />`. Dark mode follows Flux's appearance setting (light, dark or system), stored in the browser.

### Tests

Tests are written with Pest 5:

- `tests/Feature`: tests through a public seam (HTTP requests, or a Livewire page tested by name with `Livewire::test('pages::stars.index')`). They run on the application's `Tests\TestCase` with `RefreshDatabase` against in-memory SQLite.
- `tests/Unit`: small tests for deep, pure modules only.
- `tests/Arch`: the architecture rules and the component scan.

`Tests\TestCase` calls `Http::preventStrayRequests()` and `withoutVite()`, so a test never reaches the network and doesn't need a front-end build. Fake the network edge (`Http::fake()`, Socialite fakes); don't mock our own classes.

Faked requests pass through the [outbound guard](#outbound-requests) too, so fake `https://` URLs. `Tests\TestCase` fakes the guard's DNS so that every host resolves to a public address. To make a host resolve somewhere else, call `$this->fakeDns(['internal.example.com' => ['10.0.0.1']])`; an empty list makes the host unresolvable.

### Outbound requests

Every request through Laravel's HTTP client goes through the outbound guard in [`app/Outbound`](app/Outbound). The URL must use HTTPS and its host must resolve only to public addresses. The request is then pinned to those addresses with curl's resolve option, it is never streamed, and redirects are never followed. A refused request throws `App\Exceptions\OutboundRequestBlocked`, whose message is safe to show the user. To validate a URL before saving it, call `OutboundGuard::check()`. `NEXUS_BLOCK_PRIVATE_NETWORKS` and `NEXUS_REQUIRE_HTTPS` can turn the checks off only when `APP_ENV=local`.

### Architecture rules and banned functions

[`tests/Arch/ArchitectureTest.php`](tests/Arch/ArchitectureTest.php) applies Pest's Laravel and security presets, a "no debug calls" rule and strict types for `App` and `Database`. Architecture rules can't see the anonymous classes in component files, so [`tests/Arch/ComponentScanTest.php`](tests/Arch/ComponentScanTest.php) scans every component PHP file for calls to the same banned functions (debug helpers, `env`, `eval`, `exec` and friends, `unserialize`, `extract` and the rest), including calls through a `use function` import or alias, and proves the scan catches a `dd()`. Like the architecture rules, it can't see dynamic calls such as `$function()` or string callables. The lists live in [`tests/Support/BannedFunctions.php`](tests/Support/BannedFunctions.php).

### Configuration and secrets

The repository is public. Never commit `.env`, databases, keys or tokens; `.gitignore` excludes them. Nexus's own settings use the `NEXUS_` prefix, and each feature documents its variables in `.env.example` in the same change that starts reading them.

### AI agents

Laravel Boost writes guidelines (`CLAUDE.md`, `AGENTS.md`), skills (`.claude/skills`, `.agents/skills`, `.cursor/skills`, `.grok/skills`) and the Boost MCP server config (`.mcp.json`, `.codex/config.toml`, `.cursor/mcp.json`, `.grok/config.toml`) for Claude Code, Codex, Cursor and Grok. [`boost.json`](boost.json) records that choice. Project-specific guidance lives in [`.ai/guidelines`](.ai/guidelines). After installing a package or editing a guideline, regenerate everything with:

```bash
php artisan boost:update
```

## Continuous integration

[`.github/workflows/qa.yml`](.github/workflows/qa.yml) runs on every push and pull request targeting `stars`: it installs the PHP and Node dependencies, builds the front end and runs `composer qa` on PHP 8.4.
