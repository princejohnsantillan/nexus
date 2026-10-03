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
git clone https://github.com/princejohnsantillan/nexus.git
cd nexus
composer setup
herd link nexus
herd secure nexus
```

This is the second version of Nexus, built on the `stars` branch. The first version is preserved at the tag `v1`.

`composer setup` installs the PHP and Node dependencies, creates `.env` from `.env.example`, generates the app key, creates the SQLite database at `database/database.sqlite`, runs the migrations, creates the key pair Nexus signs OAuth access tokens with (`php artisan passport:keys`, into `storage/oauth-*.key`, unless they exist) and builds the front end. In a checkout set up before OAuth to Nexus existed, run `php artisan passport:keys` once.

Then generate a master key for credential encryption and put the line it prints in place of the empty `NEXUS_MASTER_KEY=` in `.env`:

```bash
php artisan nexus:master-key
```

Open <https://nexus.test>. If you link the site under another name (for example `herd link nexus-t2` in a git worktree), set `APP_URL` in `.env` to match, e.g. `APP_URL=https://nexus-t2.test`.

Herd serves the site, so there is nothing to start. While working on the front end, run `npm run dev` for hot reloading. `composer dev` runs everything at once: Vite, a queue worker, the log viewer and a spare `php artisan serve`.

### Signing in

People sign in with GitHub, Google or a one-time code emailed to them; Nexus stores no passwords. To use GitHub sign-in, register an OAuth app at <https://github.com/settings/developers> with the callback URL `{APP_URL}/auth/github/callback` (e.g. `https://nexus.test/auth/github/callback`) and set `GITHUB_CLIENT_ID` and `GITHUB_CLIENT_SECRET` in `.env`.

Google sign-in is optional, and hidden until it is set up. Create an OAuth client ID of the "Web application" type in the [Google Cloud console](https://console.cloud.google.com/apis/credentials) (configure the consent screen first if it asks), add the authorized redirect URI `{APP_URL}/auth/google/callback` (e.g. `https://nexus.test/auth/google/callback`), and set `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` in `.env`. The same callback serves signing in and adding Google from Settings. Nexus asks only for the `openid`, `profile` and `email` scopes. Without both values, the sign-in page shows no Google button and Settings offers no "Add Google".

Locally you can skip the OAuth app with the dev sign-in. Set `NEXUS_DEV_SIGN_IN=true` in `.env` and seed the two dev users:

```bash
php artisan db:seed
```

The sign-in page then offers "Sign in as Dev User" and "Sign in as Second User". The same links work from the address bar or curl: `/dev/sign-in/dev` and `/dev/sign-in/second`. The dev sign-in only exists when `APP_ENV=local` and the flag is on; everywhere else it is a 404. Seeding again restores a deleted dev user.

Email sign-in works locally with nothing to set up: the `log` mailer (`MAIL_MAILER=log`) writes each email to `storage/logs/mail.log`, its own channel (`MAIL_LOG_CHANNEL`), so ask for a code on the sign-in page and read it there. Codes never go to `laravel.log`.

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

- `layouts::app` ([`resources/views/layouts/app.blade.php`](resources/views/layouts/app.blade.php)) is the signed-in app: a Flux sidebar with Stars, Connections and Activity that collapses into a menu on small screens, the plan card above the profile, and a profile menu with Billing, Settings and the appearance switch. It is the default page layout.
- `layouts::public` is for public pages such as the welcome, Pricing, sign-in and legal pages. Choose it with `#[Layout('layouts::public')]`.
- Both include [`partials/head.blade.php`](resources/views/partials/head.blade.php), which loads the fonts (`@fonts`), the Vite assets and `@fluxAppearance`; both end with `@fluxScripts`.
- Shared pieces live in `resources/views/components`: `<x-empty-state>` (every list needs a helpful empty state), `<x-appearance-switch>`, `<x-app-logo>`, `<x-icons.github>` and `<x-icons.google>` (the services' marks), `<x-connection-header>` (a breadcrumb back to Connections, a Connection's logo, name, status, handle and account label, and its row of sub-page links), `<x-connection-status>` (a Connection's status as a pill in its status colour), `<x-account-label>` (the account a Connection signed in as and its "use for" note, inline; add `separated` to put a " · " before each part when it follows other text), `<x-tool-hint>` (one hint as yes, no or not stated; `tone` colours a yes like that hint's badge), `<x-tool-hints>` (the hints a tool declared true, as badges: read-only green, destructive red, open-world amber, idempotent neutral), `<x-star-header>` (a Star's name, description and row of sub-page links), `<x-connection-picker>` (checkbox cards for choosing a Star's Connections; bind it like a checkbox group), `<x-own-oauth-app>` (the optional client ID and secret of an OAuth app the user registered on a custom server, with the callback URL to register; bind `clientId` and `clientSecret`) and `<x-connector-logo>` (a service's logo: `:connector="$connection->connector()"`, on a tile at `size="md"` for cards and headers or `sm` for lists and pickers, or bare at `xs` for badges; a server icon for a custom server). The Star chart components (section and danger cards, stat tiles, the sparkline and the code panel) are described [below](#the-star-chart-design-system).
- `<x-sign-in-frame>` is the frame of the sign-in pages (boards S1–S3): the logo, the page's own content in the middle of the left column with the open-source line under it, and `<x-star-chart>` filling the right on wide screens.
- `<x-code-input>` is a one-time code in six boxes split 3 + 3 (Flux's OTP input): bind it with `wire:model`, and give it a `label` and a `hint`. Pasting fills every box and the form submits once the last is filled; while its property has an error the boxes turn red, keep their digits and show the error.
- `<x-code-resend>` is the row under a code input: a countdown from `seconds`, then a link that calls the Livewire `action` to send another code (pass `failed` after a wrong code to put it first), with the other way out in its slot.
- `<x-star-chart>` is the picture of Nexus on the welcome and sign-in pages: Connections (GitHub, Linear, Notion) flowing into a Star and out to Claude Code, Cursor and Codex, on a night sky. It stays dark in both themes and fills the box you give it (the drawing scales to the width, its rings run to the edges), so size and round it from the page; an optional caption goes in its slot, along the bottom.
- `<x-tool-risk>`: a tool's risk group (`App\Enums\ToolRisk`) as a badge: read-only green, writes blue, destructive red, not declared neutral. `<x-tool-hints>` renders nothing for a tool that declared no hints.
- `<x-permission-row>`: a tool or prompt on a Star as a permission: its exposed name in the `name` slot, `title`, `description` and an optional `hints` slot on the left, and the switch with who set it, as the slot, on the right.
- `<x-star-header>` is the top of every Star page: a breadcrumb back to the Stars, the Star's name with its access-mode badge and its description, the URL clients add (the signed URL in signed-URL mode) with a Copy button, and its row of sub-page links. Pass `current` with the page being shown.
- `<x-filter-chip>`: a filter as a chip that opens the `flux:menu` in its slot: a dashed "+ Star" while unset, "Star Work" on the accent wash once its `value` is set.
- `<x-activity-status-icon>`: how a call ended, as the round icon that leads its Activity row.
- `<x-activity-detail>`: one Activity entry in detail, for the Activity page's panel and flyout: its status and exact time, the fix for how it ended, what the entry stores, and that Nexus never stores arguments or results. Its buttons call the page's `closeEntry`, `switchOn` and `refreshTools`.
- `<x-action-required :problems="…" />` is the sidebar's "Action required" card: the first of the user's Connections that needs attention, how many tools in which Stars it makes unavailable, and its fix, from `ConnectionProblems::forUser()`. The app layout shows it above the profile, with an amber dot and the count beside Connections.
- `<x-star-problems :star="$star" />` is a banner for each of a Star's Connections that needs attention (what is wrong, how many of the Star's tools are unavailable, and the fix). `<x-star-header>` shows it under the header of every Star page.
- `<x-tool-flyout>`: the tool details flyout (a `flux:modal` flyout named `tool-details`) for an `App\Stars\ToolDetails`: the exposed and server's names, title, full description, hints, parameters, which of the user's Stars have the tool on, and its latest calls. A Star's Tools page puts the tool's switch in its `switch` slot. `<x-tool-flyout.trigger :tool="$tool">` is a tool's name as the button that opens it: the page's `showToolDetails($toolId)` keeps the id in `detailsToolId` and shows the modal, and closing it forgets the id and puts focus back on the trigger.
- `<x-empty-state compact>`: a smaller empty state, with its heading one level down, for a list inside a card or a flyout.
- `<x-plan-card :user="$user" />`: the sidebar's plan card (board P8). The app layout shows it under `<x-action-required>`, above the profile, on every page but the Upgrade page it leads to. See [Plans and billing](#plans-and-billing) for its states.
- `<x-usage-meter :label="__('Stars')" :used="$count" :limit="$limit" />`: one figure of the Billing page's usage row: the count "of" the limit and a bar, amber with "· limit reached" at the limit; a null limit reads "of unlimited · No limit on Pro".
- `<x-billing-period-picker wire:model.live="period" />`: Monthly or Yearly as a segmented radio group, with what Yearly saves on its badge, bound to an `App\Enums\BillingPeriod` value.
- `<x-free-plan-card :current="true" />` and `<x-pro-plan-card :period="$period" />`: the two plans as cards to choose from, with their prices and what they include from `nexus.plans` (Pro priced for the period). `current` gives Free a "Your plan" badge; an `action` slot puts a button (or a callout) at the bottom of either.
- `<x-adding-to-star :star="$star" />`: the banner that says the user is adding a Connection to a Star and will go back to it once it's connected (see [Adding a Connection from a Star](#adding-a-connection-from-a-star)); `cancel` adds a Cancel that goes back to the Star, adding nothing.
- `<x-handle-preview :handle="$handle" />`: the tool name agents will see, `handle__tool`, under a handle field, following the Livewire property as the user types (`model`, `handle` unless you say), and that the handle can't change later. Pass a `tool` the server has, or it shows `<tool>`.
- `<x-public-header />`: the top of the public pages (welcome, Pricing and legal; board P7): the logo, "Pricing" and "Sign in", or "Go to Stars" for a signed-in visitor. There's no appearance menu, as on the boards: public pages follow the browser's theme, or the one picked in the app. The Pricing page passes `current="pricing"` to mark its link; it's a prop rather than the route because a Livewire update re-renders the header from another route.
- `<x-public-footer />`: the bottom of the public pages: "Nexus is open source." with the slot after it (a page's own sentence), then links to Terms, Privacy, Refunds and GitHub, the current page marked.
- `<x-legal-page :title="…" updated="2026-10-03">`: a legal page between the public header and footer: the draft note (see [Terms, Privacy and Refund pages](#terms-privacy-and-refund-pages)), the title, "Last updated" with the `updated` date, and the text as the slot, one column about 680px wide. Write the slot as plain `<h2>`, `<p>`, `<ul>` and `<strong>`: the component styles them.
- `<x-contact-email />`: the contact address (`NEXUS_CONTACT_EMAIL`) as a mailto link.
- Route parameters for records resolve only within the signed-in user's own data (`bindOwnRecords()` in `AppServiceProvider`), so another user's Connection is a 404, never a 403. Bind each new record type there the same way. The MCP endpoint (`/mcp/{star}`) is the exception: it has no session, so it binds nothing and its access middleware finds the Star.
- Every page except the welcome, sign-in and legal pages requires sign-in: add app routes inside the `auth` group in [`routes/web.php`](routes/web.php). Guests are sent to the sign-in page (`/sign-in`, `pages::auth.sign-in`) and come back to the page they asked for once they sign in, so every way of signing in ends with `redirect()->intended(route('stars.index'))`. Signed-in visitors to the welcome or sign-in page go to the app.
- Toasts: in a Livewire action call `Flux::toast(...)`. To show one after a redirect, flash `toast` with its text and a variant (`success`, `warning` or `danger`): `to_route('home')->with('toast', ['variant' => 'success', 'text' => __('Saved.')])`. Both layouts render it with `<x-flash-toast>`.

### UI with Flux free

Use only Flux's free components (layouts, navlist and navbar, button, input, textarea, native select, checkbox, radio, switch, field, heading and text, badge, callout, card, table, pagination, modal, toast, dropdown and menu, tooltip, avatar, profile, separator, icon, brand), themed as the [Star chart](#the-star-chart-design-system) below. Pro components (tabs, accordion, popover, command, autocomplete, searchable or multiple select, date picker, chart) are not available: use a segmented `flux:radio.group` for tabs and pickers, a `flux:modal variant="flyout"` for drawers and `<x-sparkline>` for small charts. Icons are [Heroicons](https://heroicons.com) by name, e.g. `<flux:icon.star />`; an icon Heroicons lacks goes in `resources/views/flux/icon` in Flux's custom icon format, like `<flux:icon.pulse />` for Activity. Dark mode follows Flux's appearance setting (light, dark or system), stored in the browser.

Flux's switch keeps its own on/off state once drawn, and doesn't follow a changed `checked` attribute when Livewire updates the page. When the server decides a switch's state (as on a Star's Tools page, where one click can change many), key it by that state, e.g. `wire:key="switch-{{ $id }}-{{ $on ? 'on' : 'off' }}"`, so a changed switch is drawn afresh.

### The Star chart design system

Nexus looks like a printed star chart: ink on white, hairline borders instead of shadows, and one ultramarine, Atlas blue, for what is on and what to do next. Status colours appear only where status lives. The tokens are set in [`resources/css/app.css`](resources/css/app.css), each with a dark-mode value, so use the utilities below rather than raw colours, and give every neutral a `dark:` partner as the existing views do.

| Token | Utility | Use |
| --- | --- | --- |
| Paper / Wash | `bg-white` / `bg-zinc-50` | page and card ground / sidebar, table headers, card footers |
| Rule / Rule strong | `border-zinc-200` / `border-zinc-300` | hairlines / inputs |
| Muted / Graphite / Ink | `text-zinc-500` / `text-zinc-600` / `text-zinc-950` | icons and meta / secondary text / text, dark buttons, the code panel |
| Atlas blue | `bg-accent`, `text-accent-content`, `text-accent-foreground`, `bg-accent-wash`, `text-accent-strong` | Flux's accent: primary buttons, switches that are on, focus rings, the current sidebar item, links. Accent strong is text on Accent wash where a board asks for it (#2333B0, lighter in dark mode), such as the Pricing page's pill |
| Success, Warning, Danger | `text-success` on `bg-success-wash`, `text-warning` on `bg-warning-wash` (`border-warning-rule` around it), `text-danger` on `bg-danger-wash` (`border-danger-rule` around it) | connected, OK, read-only / needs sign-in, attention, limits / errors, destructive |

- Flux's gray (`zinc`) is re-assigned to the Star chart's cool neutrals and its `red-500` and `red-600` to signal red, so Flux's own components follow the design. Atlas blue is Flux's accent, set the way [Flux's theming](https://fluxui.dev/docs/theming) documents it: `--color-accent`, `--color-accent-content` and `--color-accent-foreground`, with dark values under `.dark`. Focus rings are drawn once for everything, Flux's controls included, by the `:focus-visible` rule in `app.css` (2px of the accent, 2px out), so don't give a control focus styles of its own.
- Type: Inter for everything people read; JetBrains Mono (`font-mono`) for anything people type or paste: handles, tool names, tokens, URLs and code. Both load through `@fonts` from the `fonts` list in [`vite.config.js`](vite.config.js).
- Shape: a 4px grid; radius 6 for inputs (`rounded-md`), 8 for buttons (`rounded-lg`) and 12 for cards (`rounded-xl`).

Build sections from these components in `resources/views/components`:

- `<x-section-card>`: one section of a page in a card, with a `heading`, an optional `description` and an optional `aside` slot beside them (such as a button), the body as its slot, and an optional footer with a `hint` slot on the left and an `actions` slot on the right. Give it `as="form"` and `wire:submit` to make the card the form, so a submit button in the footer submits it.
- `<x-danger-card>`: a section card for a destructive action, with a red-tinted footer. Its hint says "This can't be undone." unless you pass one; put the action, usually a `flux:modal.trigger` for the confirmation, in `actions`.
- `<x-stat-strip>` of `<x-stat-tile>`s: figures in one card, side by side (stacked on a phone). A tile has a `label` and `value`, optional `secondary` text beside the value, a `tone` (`success`, `warning` or `danger`) for the value and a `spark` of recent values, oldest first, drawn as an `<x-sparkline :values="…" />`.
- `<x-code-panel>`: code, config or a command on a dark panel. Its header names the `file` it goes in, or says "terminal" (or your own `label`), and its Copy button copies exactly the `code` shown.
- `<x-step>`: one numbered step of a how-to, as an item of an `<ol>`: its `number` in a circle, a `heading` with an optional `aside` slot on the right (such as a link), and the step's body, usually a code panel, as its slot. `pending` draws the number as an outline, for a step still to happen, like "Check it works".
- `<x-unsaved-changes-bar>`: the one Save for a page whose sections are edited together (a Star's overview). While `unsaved`, a dark bar says "Unsaved changes", lists `consequences` (what saving would do, such as `App\Stars\StarChanges` works out) and offers Discard and Save changes (the component's `discard` and `save` actions). Render it always, as the last thing on the page: it sticks to the bottom of the window and takes its own room at the end, so it never covers the last section, and its status line tells screen readers what is unsaved. After a save that fails validation it says so and brings the first field in error into view.
- `<x-not-saved>`: a "Not saved" pill for a row changed since the last save. `<x-connection-picker>` shows it on the Connections whose ids you pass as `unsaved`.
- Leaving a page with unsaved changes asks first: put `x-data="unsavedChangesGuard('modal-name', ['name', …])" x-bind="guard"` on the Livewire page's root, naming the properties it guards (others, such as a picker's choice, never count), give the component a `hasUnsavedChanges` property (`#[Locked]`, set in `dehydrate()`) and add a Flux modal of that name whose leave button calls `leave()`. Links and redirects with `wire:navigate`, and the browser's back and forward buttons between such pages, open the modal; closing or reloading the tab gets the browser's own prompt. The guard lives in [`resources/js/app.js`](resources/js/app.js).
- Email: Nexus's emails are Markdown notifications (`<x-mail::message>` views in `resources/views/mail`), drawn by the Star chart mail theme in [`resources/views/mail/star-chart.css`](resources/views/mail/star-chart.css), which `config/mail.php` sets as `mail.markdown.theme`: a white card with a hairline border on Wash, Inter, Ink headings, Graphite text and an Atlas blue button (`<x-mail::button align="left">`). Laravel's mail layout asks clients for light mode only, so the theme has no dark partner. Locally they land in `storage/logs/mail.log`.

```blade
<x-section-card as="form" wire:submit="saveDetails" :heading="__('Details')" :description="__('Agents see the description, so say what the Star is for.')">
    <flux:input wire:model="name" :label="__('Name')" />

    <x-slot:hint>{{ __('Renaming keeps the endpoint URL.') }}</x-slot:hint>
    <x-slot:actions>
        <flux:button type="submit" variant="primary" size="sm">{{ __('Save') }}</flux:button>
    </x-slot:actions>
</x-section-card>

<x-stat-strip>
    <x-stat-tile :label="__('Calls · 24h')" value="1,284" :spark="[3, 5, 4, 8, 6, 9]" />
    <x-stat-tile :label="__('Errors · 24h')" value="3" secondary="0.2%" tone="danger" />
</x-stat-strip>

<x-code-panel file="~/.codex/config.toml" :code="$snippet" />
```

### Tests

Tests are written with Pest 5:

- `tests/Feature`: tests through a public seam (HTTP requests, or a Livewire page tested by name with `Livewire::test('pages::stars.index')`). They run on the application's `Tests\TestCase` with `RefreshDatabase` against in-memory SQLite.
- `tests/Unit`: small tests for deep, pure modules only.
- `tests/Arch`: the architecture rules and the component scan.

`Tests\TestCase` calls `Http::preventStrayRequests()` and `withoutVite()`, so a test never reaches the network and doesn't need a front-end build. Fake the network edge (`Http::fake()`, Socialite fakes); don't mock our own classes. It also gives Passport an RSA key pair (`Tests\Support\PassportKeys`, made once per test process and never stored), so OAuth to Nexus runs for real in tests.

Production runs on Postgres, so CI runs the suite on Postgres as well as SQLite. To run it on a local Postgres, point the `DB_*` variables at a database whose user may create databases (a parallel run makes one per process):

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=nexus_testing DB_USERNAME=nexus DB_PASSWORD=secret vendor/bin/pest --parallel
```

Write SQL that works on both. On Postgres a statement that fails aborts the transaction it ran in, and every later query in it fails too, so a write that may be refused and caught runs in its own `DB::transaction()` (a savepoint inside another transaction), as `App\Actions\RecordActivity` does.

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
- `withoutTools()` makes it a server with no tools: it doesn't declare the `tools` capability, and `tools/list` and `tools/call` are "method not found".
- `withPrompts()` gives it prompts: it declares the `prompts` capability and lists them, as arrays or a JSON string sent exactly as written, paginated like the tools. Without it, `prompts/list` and `prompts/get` are "method not found". `onGetPrompt()` answers `prompts/get` for a prompt (an array, or its raw JSON); unknown prompts get "invalid params", and the others one user message saying "ok".
- `speaking('2026-07-28')` answers `server/discover`; `speaking('2025-06-18')` or `'2025-03-26'` answers `initialize` with that version.
- `requireHeader('Authorization', 'Bearer …')` refuses other requests with a 401 (or the status you pass) and the `WWW-Authenticate` header set by `challengingWith()`.
- `streaming()` answers with server-sent events instead of plain JSON; `streaming(splitData: true)` spreads each message over several `data:` lines, with CRLF line ends, a comment and an id.
- `respondTo($method, $responder)` replaces the answer to one method. The responders are `FakeMcpServer::error()` (a JSON-RPC error), `errorWithId()` (one carrying a placeholder or another request's id), `httpStatus()`, `raw()` (any body, e.g. malformed JSON), `timeout()` and `unreachable()`.
- `beforeAnswering($method, $callback)` runs the callback while that request is in flight, to play out a race such as the Connection being deleted or moved mid-refresh.
- `requireOAuth()` puts it behind an OAuth authorization server, by default a new [`Tests\Support\FakeAuthorizationServer`](tests/Support/FakeAuthorizationServer.php) at `https://auth.example.com` (`authorizationServer()` returns it). The server publishes its protected-resource metadata, refuses requests without an access token that authorization server issued with a 401 challenge naming the metadata, and accepts the tokens until they expire. Its arguments script the metadata and the challenge: the `resource` it names, the `scope` the challenge asks for, `scopesSupported`, whether the challenge names the metadata at all, whether it lives under the server's path, at the root or nowhere, and the `authorizationServers` it lists.

The fake authorization server publishes RFC 8414 metadata with S256 PKCE, registers clients dynamically (each with a secret), issues access tokens that last an hour and rotates refresh tokens, refusing a used one. It checks what a real one does: the client and its secret, the redirect URI, the PKCE verifier and the resource. `approve($authorizationUrl)` plays the user approving Nexus on its sign-in page and returns the callback URL to visit; `deny()` plays them refusing. Script it with `withoutRegistration()`, `registeringPublicClients()`, `acceptingClient()`, `acceptingMetadataDocuments()`, `namingItselfOnReturn()` (`iss`), `publishingMetadataAt('openid')`, `namedInMetadataAs()`, `withMetadata()`, `issuingTokensFor()`, `keepingRefreshTokens()`, `withoutRefreshTokens()`, `withTokenFields()` (more fields in every token response, such as Notion's `workspace_name`) and `respondTo('token' | 'register', $responder)`; read back `tokenRequests()` and `registrations()`. [`Tests\Support\ConnectionOAuthFlow`](tests/Support/ConnectionOAuthFlow.php) drives a sign-in through Nexus's own routes:

```php
$server = FakeMcpServer::at()->requireOAuth()->withTools([['name' => 'search']]);

ConnectionOAuthFlow::signIn($this, $connection, $server->authorizationServer()); // start, approve, callback
```

Afterwards, `received('tools/call')` returns the JSON-RPC messages it got, decoded with objects kept as objects, `requests()` the HTTP requests (for headers), and `transferOptions()` the HTTP client's options for each one, such as its timeouts. Anything sent to another URL is a stray request and fails the test.

#### Downstream text stays out of logs

Nexus never copies text a downstream server sent (its error messages, response bodies or headers) into the log, failed-job records or activity entries. The tests in [`tests/Feature/DownstreamText`](tests/Feature/DownstreamText) prove it for every way a tool call, prompt fetch, catalog refresh (on the page and on the queue), Connection sign-in (discovery, registration, callback, renewal) or sign-in to Nexus can fail. [`Tests\Support\DownstreamCanary`](tests/Support/DownstreamCanary.php) does the looking: have a fake server send `DownstreamCanary::TEXT`, then

```php
$canary = DownstreamCanary::watch();            // before the request
// …
expect($canary->sightings())->toBe([]);         // every place the text turned up
```

lists each log record that contains it, or just its start (`DownstreamCanary::PREFIX`, which is all a stack trace's truncated argument keeps), as the log channel writes it, plus the stack-trace arguments of any exception it carries, and each `failed_jobs`, `jobs` or `activity_entries` row. `DownstreamCanary::failures()` (with a sample in `someFailures()`) and `tokenEndpointFailures()` are datasets of the ways an MCP server or a token endpoint can fail, each carrying the text. Add a case there for every new request Nexus makes to a server.

### Outbound requests

Every request through Laravel's HTTP client goes through the outbound guard in [`app/Outbound`](app/Outbound). The URL must use HTTPS and its host must resolve only to public addresses. The request is then pinned to those addresses with curl's resolve option and always connects directly, ignoring any proxy from the environment or the request options. It is never streamed, and redirects are never followed. A refused request throws `App\Exceptions\OutboundRequestBlocked`, whose message is safe to show the user. To validate a URL before saving it, call `OutboundGuard::check()`. `NEXUS_BLOCK_PRIVATE_NETWORKS` and `NEXUS_REQUIRE_HTTPS` can turn the checks off only when `APP_ENV=local`.

### Downstream MCP servers

[`App\Downstream\DownstreamClient`](app/Downstream/DownstreamClient.php) is the only way Nexus talks to a Connection's MCP server. It opens a session signed in the way the Connection says (no auth, its header, or its OAuth access token) and connects on the first request:

```php
$session = $downstream->session($connection);

$tools = $session->listTools();                                 // list<string>: each tool's JSON, every page
$result = $session->callTool('search', '{"query":"laravel"}');  // JSON object in, JSON object out

$session->offersPrompts();                                      // bool: whether the server declared the prompts capability
$prompts = $session->listPrompts();                             // list<string>: each prompt's JSON, every page
$result = $session->getPrompt('summarize', '{"page":"x"}');     // JSON object in (the arguments), JSON object out (the messages)
```

- Tools, prompts and results come back as the exact JSON text the server sent, and the arguments of a tool call or a prompt go out as the exact JSON object given. Nothing is decoded and encoded on the way, which would turn `{}` into `[]`, round long numbers and fail on ones like `1e400`. Decode a copy with `json_decode()` to read it; keep the text to store or forward it. `App\Downstream\RawJson` cuts members and elements out of JSON text without decoding them.
- It speaks 2026-07-28 (`server/discover`) where the server does, and otherwise falls back to `initialize`, accepting servers that settle on 2025-11-25, 2025-06-18 or 2025-03-26. It follows `tools/list` and `prompts/list` cursors, pairs errors that come back with a placeholder or mismatched id with their request, and reads server-sent events as the SSE format defines them (an event's `data:` lines joined).
- `NEXUS_DOWNSTREAM_CONNECT_TIMEOUT` (10 s) limits connecting and the handshake; `NEXUS_DOWNSTREAM_CALL_TIMEOUT` (55 s, under Laravel Cloud's 60-second request limit) limits listing and calling tools and listing and getting prompts. The configured call timeout is also the most a session's requests take altogether, counted from when it is opened (just before its first request), even when `withCallTimeout()` shortens each request's wait: each request gets the time left when that is less, renewing an OAuth token waits for its lock and the token endpoint only that long, nothing more is sent once it has run out (that fails as `Timeout`), and ending the session is skipped. So a slow handshake can't push a tool call past the request limit.
- An OAuth Connection's access token is read for every request and renewed first when it has expired or is about to (see [Signing in with OAuth](#signing-in-with-oauth)). A session stays bound to the server it was opened for: once the Connection signs in somewhere else, the session gets no token, so no server ever receives another's. A Connection that isn't signed in, or whose server refuses to renew its sign-in, fails as needing sign-in before anything is sent.
- `$downstream->signInChallenge($connection)` asks the server, without signing in, how to sign in: the `WWW-Authenticate` challenge it refuses Nexus with, or null when it lets Nexus in.
- Every failure throws `App\Exceptions\DownstreamRequestFailed`. Its `failure` (`App\Enums\DownstreamFailure`) says why: `NeedsSignIn` (401, 403 or an invalid-token challenge, whose `challenge` the exception keeps, or a request the HTTP client can't build because the Connection's URL or credentials hold characters a request can't carry), `Timeout`, `Unreachable` (connection failed, blocked by the outbound guard, 404 or 5xx), `ProtocolError` (including a JSON-RPC error answering anything but a tool call, such as `prompts/get`) or `ToolError` (a JSON-RPC error instead of a tool result). Its message is Nexus's own and safe to show; it never contains text from the server, and no exception from the server is chained to it. A tool result with `isError: true` is a result, not a failure.

The pieces behind it (the transport, the lenient protocol, the raw request) live in `App\Downstream` too; reach them only through the client.

### Users and sign-in identities

A user signs in through one of their **sign-in identities** ([`App\Models\SignInIdentity`](app/Models/SignInIdentity.php)): an account on a provider (`App\Enums\IdentityProvider`: GitHub, Google, or an email address signed in with a code), named by the provider's own id for it (GitHub's numeric id, Google's `sub`, or the address), with a `login` to show (the GitHub login, or the email address). Each provider's account belongs to one user only. Signing in finds the user by the identity, and one nobody has creates a new account; [`App\Actions\SyncGitHubUser`](app/Actions/SyncGitHubUser.php) does this for GitHub and refreshes the profile, and [`App\Actions\SyncGoogleUser`](app/Actions/SyncGoogleUser.php) does it for Google, taking a new account's name, email address and avatar from Google. An identity is only ever added to an existing user from Settings while they're signed in, never by matching an email address. Deleting the account deletes its identities.

- **Adding** goes through [`App\Actions\AddSignInIdentity`](app/Actions/AddSignInIdentity.php), for any provider. It refuses an identity another user has by throwing `App\Exceptions\IdentityBelongsToAnotherUser` (the caller words the message), and returns one the user already has with `wasRecentlyCreated` false. "Add Google" in Settings goes to `/settings/sign-in-methods/google`, which remembers in the session who is adding it, so Google's one callback (`/auth/google/callback`) adds the account to them instead of signing in with it.
- **Removing** goes through [`App\Actions\RemoveSignInIdentity`](app/Actions/RemoveSignInIdentity.php), for any provider, and only while another identity remains: the last one can't be removed, so an account always has a way in. Removing GitHub also clears the user's old GitHub columns, so GitHub sign-in can't find the user by them and give the identity back. Afterwards, signing in with a removed identity creates a new account.

Signing in with an email address uses a one-time code ([`App\Auth\EmailCodes`](app/Auth/EmailCodes.php)): six digits, of which only a hash is stored (`App\Models\EmailCode`), working once, for 10 minutes and 5 tries, and only for its purpose (`App\Enums\EmailCodePurpose`: signing in, or adding the address in Settings for the user who asked). Sending a new code replaces the last one, and using a code uses up any other for the same purpose and user. Sending is limited per address (a minute between codes, and `NEXUS_EMAIL_CODES_PER_ADDRESS_PER_HOUR`) and per IP address (`NEXUS_EMAIL_CODES_PER_IP_PER_HOUR`), checked and counted while the send holds cache locks for the address and the IP address, so two sends at once can't both slip through, and answers the same whether or not the address has an account; in Settings, an address someone else signs in with is refused only once its code proves it's the user's. The code never reaches a log, a job or an exception: the email is sent at once, never queued (like Laravel's password reset it's a notification, which Pest's Laravel preset lets stay unqueued), the parameters carrying it are `#[SensitiveParameter]` (the hasher is injected, not called through its facade, whose arguments a trace would keep), and a failed send withdraws the code, logs only the kind of failure and throws `App\Exceptions\EmailCodeNotSent` with nothing chained, since the failure's own trace holds the email. [`App\Actions\SignInWithEmail`](app/Actions/SignInWithEmail.php) finds or creates the account, and `App\Actions\AddSignInIdentity` adds the address to the signed-in user. `EmailCodes::canBeSent()` decides whether email is offered at all: everywhere except production with a mailer that delivers nothing (`log` or `array`). Expired codes are pruned daily. The email is `App\Notifications\EmailCodeNotification`, sent to the address on demand; in tests, `Notification::fake()` catches it and `Tests\Support\SentEmailCodes` reads its code.

Show who a user is with `$user->signInName()` (`@octocat`, or else an email address) and `$user->gitHubLogin()`, never the users table's `github_id` and `github_login`. Those are left from before identities and may be empty: GitHub sign-in still writes them, for code that reads them, and uses `github_id` only to give a user who signed up before identities existed their identity. A later migration drops them. In tests, `User::factory()` makes a user with no identities; give them one with `signsInWithGitHub('octocat')`, or with `SignInIdentity::factory()` and its `gitHub()`, `google()` and `email()` states.

### Connections and their catalogs

A Connection (`App\Models\Connection`) is one of a user's accounts on a remote MCP server. Its handle prefixes its tools' names in Stars, so it never changes once created: the model refuses to save a changed handle. A header sign-in keeps the header's name in `settings` and its value encrypted in `secrets`. The user's plan caps how many they may add (10 on Free, none on Pro; see [Plans and billing](#plans-and-billing)). Save every new Connection through `App\Actions\SaveNewConnection`: it holds a per-user cache lock while it counts and inserts, so two adds at once can't both slip under the limit, and its `limitMessage()` is what the user is told. Load tools after it returns, outside the lock. Delete one through `App\Actions\DeleteConnection`, which holds its row while it deletes it, as adding Connections to a Star does, so a Star it is being added to at that moment either gets it in time to have its lists forgotten or finds it gone (see [Caching a Star's lists](#caching-a-stars-lists)).

A Connection's catalog is its stored copy of the server's tools (`App\Models\ConnectionTool`) and prompts (`App\Models\ConnectionPrompt`). [`App\Actions\RefreshCatalog`](app/Actions/RefreshCatalog.php) re-reads it:

```php
$loaded = $refreshCatalog->handle($connection);  // bool
```

It matches tools by name, rewrites only the ones whose definition hash changed, removes vanished ones, skips names the MCP specification doesn't allow, stores each definition as the exact JSON received with its four behaviour hints (null when the server didn't state one), and marks the Connection connected. When the server can't be listed, the previous catalog stays and the Connection's status (`needs_auth` or `error`) and last error say why; nothing is logged.

Prompts are refreshed with the tools, in the same session, when the server declares the `prompts` capability: matched by name and hash the same way and stored as the exact JSON received with their title and description. The MCP specification sets no rule for prompt names, so any name is kept (`team:review`, `résumer`), with two exceptions. **Prompt names are limited to 128 characters** (`ConnectionPrompt::NAME_MAX_LENGTH`): the specification sets no maximum, so this is Nexus's own product limit, the same as for tool names and the length of the name columns, which activity entries share between tools and prompts. A prompt whose name is longer is skipped, and so is one whose name is empty or has control or invisible characters, which could make two names look alike. A server that declares no prompts isn't asked and keeps none. Likewise a server that declares capabilities without `tools`, such as one with only prompts, isn't asked for tools and has none; one that declares no capabilities at all is still asked. When listing them fails, the previous prompts stay and the refresh still succeeds, with no error recorded: the tools matter most. `ConnectionPrompt::arguments()` reads the arguments a prompt declares (name, title, description, required) from its definition for the Prompts page.

When the Connection's connector names a profile tool (GitHub's `get_me`) and the server lists it, the refresh also calls it with no arguments and stores the account it names as the Connection's `account_identity` (see [Accounts and siblings](#accounts-and-siblings)); a profile tool that fails or names no one leaves the account as it was and never fails the refresh.

Asking the server takes time, so the outcome is written in one transaction holding the Connection's row, and only if the Connection still exists with the same URL, sign-in method, settings and credentials (the stored ciphertext, which changes with every new value); a refresh overtaken by a delete, a new server or a replaced header is dropped, whether it succeeded or failed. An OAuth Connection's credentials aren't compared, since its access token is renewed as it is used, perhaps by the refresh itself; each new sign-in changes its settings instead. A database error while storing is reported as `CatalogNotStored`, which names the Connection and the SQLSTATE but none of the values (they came from the server), and recorded on the Connection under the same check. Adding a Connection and its "Refresh tools" button run it straight away; changing a Connection's URL clears its stored credentials and its catalog first.

A Connection **needs attention** when its status is needs sign-in or error: agents can't use its tools until the user fixes it. [`App\Stars\ConnectionProblems`](app/Stars/ConnectionProblems.php) works this out for the sidebar, the Stars' cards, the banner on a Star's pages and the Connections page, so they agree. `forUser($user)` finds the user's own such Connections in one query, each as a `ConnectionProblem` with the Stars that include it and how many of its tools are on in each (by the same rule as `StarToolset`); `forStar($star)` and `byStar($user)` read the same, and `ConnectionProblems::of($connection)` judges a Connection already loaded. A problem says what is wrong (`headline()`, `summary()`, `reason()`) and how to fix it: `fixUrl()` is the Reconnect route for an OAuth Connection that needs sign-in and the Connection's page for anything else.

#### Keeping catalogs fresh

Catalogs also refresh themselves in the background, on the queue, through [`App\Jobs\RefreshCatalogInBackground`](app/Jobs/RefreshCatalogInBackground.php), which runs `RefreshCatalog` for one Connection:

- **Every day**: the scheduler runs `php artisan nexus:refresh-catalogs`, which queues a refresh for every Connection. Run it by hand to refresh them all now.
- **When a Star lists stale tools or prompts**: `tools/list` and `prompts/list` queue a refresh of each of the Star's Connections whose catalog is older than `NEXUS_CATALOG_STALE_AFTER_MINUTES` (360, so 6 hours) or never loaded ([`App\Actions\RefreshStaleCatalogs`](app/Actions/RefreshStaleCatalogs.php)). It queues them in a `defer()` callback, after the response is sent, so the list is served from the catalog as it is and never waits. Each Connection is queued this way at most once per stale age, so a server that keeps failing, whose catalog stays stale, isn't asked again on every list.
- **When the server doesn't know a tool**: a `tools/call` that the server refuses as "invalid params" (JSON-RPC `-32602`, what the MCP specification has servers answer for an unknown tool), or answers with a tool error saying so ("Unknown tool: search", "Tool search not found", as servers built on the Python and TypeScript SDKs do), queues a refresh after the response, so the next list no longer has the tool. The error text is only matched, never kept.

The job is unique per Connection: while one is queued or running, no other is queued. Its lock goes when it has run or failed, including when a worker dies during it, since the queue then hands it out again and it fails as attempted too many times. The lock lasts at most 30 days, so a lock whose job was lost from the queue clears itself. On Laravel Cloud's SQS-based queue no job waits that long (SQS keeps a message 14 days at most); the database queue keeps jobs however old, so only a backlog of more than 30 days could queue a second refresh there. It is tried once and carries only the Connection's id, so a Connection deleted meanwhile is skipped. A server that can't be listed is recorded on the Connection, as `RefreshCatalog` records it, and nothing is logged.

The worker stops the job after 60 seconds, well before Laravel Cloud's 90-second Flex queue limit and the database queue's 90-second `retry_after`. PHP can only stop it once the request in flight returns, so the job waits at most 20 seconds for each request to the server (`DownstreamClient::withCallTimeout()`), rather than the 55 a "Refresh tools" click waits: a request sent just before the 60th second still ends by the 80th. Its requests take 55 seconds at most altogether too, like any session's, so a refresh usually ends before the worker has to stop it. A refresh the worker stops (it took too long, or a worker died during it) is recorded on the Connection by the job's `failed()`, and `bootstrap/app.php` keeps the worker's "attempted too many times" out of the log (`RefreshCatalogInBackground::isGivenUp()`). The worker stops it with an exception made wherever the refresh is, perhaps reading the server's answer, and the failed job keeps that exception's trace, so the refresh runs with `zend.exception_ignore_args` on: the trace names each call without its arguments, never the start of the server's text. `failed()` compares the Connection with what it was when the refresh started (its server, sign-in, status, last error and refresh time, kept hashed in the cache under the refresh's own id, so a refresh queued once this one's lock is gone never shares it), so a newer sign-in or refresh is never overwritten, while a new name or note doesn't stop the failure being recorded.

Locally the queue is the database: `composer dev` runs a worker, or run `php artisan queue:work` on its own. To check a stale refresh by hand, make a Connection's catalog old (`php artisan tinker --execute 'App\Models\Connection::find(1)->forceFill(["catalog_refreshed_at" => now()->subDay()])->save();'`), list a Star's tools with a client or `curl`, and watch the worker run `RefreshCatalogInBackground` and the Connection's "Last refreshed" change. The daily refresh needs the scheduler (`php artisan schedule:work`); on Laravel Cloud it runs on the app cluster.

### Accounts and siblings

A user may connect a service more than once, such as a work and a personal GitHub account. What tells them apart is the Connection's **account label**: its name, the account it signed in as (`account_identity`, detected) and what the user said to use it for (`description`, the "use this account for" note). `$connection->accountLabel()` writes it the way agents read it: `GitHub · octocat — use for: work`.

[`App\Actions\DetectAccountIdentity`](app/Actions/DetectAccountIdentity.php) detects the account, best effort and for labels only (nothing is authorised by it):

- from an OAuth token response, when it names the person (`email`, `preferred_username`, `login`, `username`, or the same under `user`, or the claims of an OpenID Connect ID token, read unverified) or the workspace (Notion's `workspace_name`, Slack's `team.name` and the like), as "person @ workspace". Each new sign-in stores what its response names (none clears it, since the user may have signed in to another account), and a renewal that names one updates it.
- from the connector's profile tool at every catalog refresh (GitHub's `get_me`, read as `login`). Only connectors declare one, so Nexus never calls a custom server's tools on its own.

What the server sent is flattened to one line, without control or invisible characters, and cut to 100 characters. Changing a custom server's URL or sign-in method, or replacing a header value or token, forgets the account until the refresh or next sign-in detects it again.

The account label shows on the Connections list, every Connection page (whose overview has an Account row), the Star Connection pickers and a Star's Tools page. A Connection's **service** (`serviceKey()`) is its connector, or for a custom server the host of its URL. On a Connection page, the user's other Connections of the same service (`sameServiceConnections()`) are named beside "Refresh tools" and in its toast, since refreshing reloads only this account.

Connections of the same service in one Star are **siblings** (`Connection::idsWithSiblings()`). A Star tells agents which is which: each sibling's tools' descriptions start with its account label (see [Stars and their tools](#stars-and-their-tools)), and its instructions say so.

### Signing in with OAuth

A Connection whose `auth_type` is `oauth` signs in on its server's own sign-in page, with Nexus as the OAuth client. [`App\ConnectionOAuth\ConnectionSignIn`](app/ConnectionOAuth/ConnectionSignIn.php) runs it:

```php
$url = $signIn->start($connection);            // the server's sign-in page; redirect the user there
$connection = $signIn->finish($user, $query);  // when the server sends them back to the callback
```

- **Starting** asks the server without credentials for its 401 challenge, then discovers its authorization server: the protected-resource metadata (RFC 9728) the challenge names, or at its well-known URL (under the server's path, then at the root), then the authorization server's metadata (RFC 8414) or OpenID configuration. A server without protected-resource metadata is its own authorization server. Discovery tolerates an issuer listed with a trailing slash its metadata doesn't have, and a resource named by a shorter URL on the same origin (which sign-in then names the way the server does); anything on another origin, another issuer, a sign-in page that isn't a well-formed HTTPS URL or no S256 PKCE is refused.
- **The client** is the first that applies: the user's own OAuth app (its client ID in `settings.oauth_client_id`, its secret encrypted), the deployment's app for the connector (`NEXUS_{KEY}_CLIENT_ID`), Nexus's Client ID Metadata Document at `/oauth/client-metadata.json` when the server accepts one and Nexus is on a public HTTPS URL (a `.test` site isn't), else dynamic client registration (RFC 7591), whose client is stored on the Connection and reused. Configured credentials are always read where they are configured, never copied onto Connections.
- **The request** uses PKCE S256, a random state, the `resource`, the connector's scopes (else the challenge's, else every scope the server lists) and, for connectors whose definition says `select_account`, `prompt=select_account`. Pending sign-ins live in the session by state, at most five, so several can run at once, even to the same server.
- **The callback**, `/oauth/callback`, is one URL for every Connection: `NexusClient::callbackUrl()`, built from `APP_URL`. It checks the state and the Connection: the user's own, still on the same server and still set up to sign in as the same client (a sign-in started before the user changed their own OAuth app is dropped, before the code is exchanged and again when the tokens are stored). A refusal is reported as one; an approval must also come from the issuer Nexus sent the user to, named in `iss` when the server says it names itself. It then exchanges the code, stores the tokens encrypted with the account the token response names (see [Accounts and siblings](#accounts-and-siblings)), loads the tools and shows the Connection with a toast. Every failure is a `ConnectionSignInFailed`, whose message is Nexus's own: it names an HTTP status or a standard OAuth error code at most. An access token is only accepted, at sign-in or renewal, if it is visible ASCII, so it can go in an `Authorization` header; an `expires_in` that isn't a whole number of seconds up to ten years counts as no stated expiry. No exception from the HTTP client gets out of [`OAuthRequests`](app/ConnectionOAuth/OAuthRequests.php), since each quotes the address or the response: an address the server gave that the client can't parse fails like one it can't reach.
- **Renewal**: [`App\ConnectionOAuth\ConnectionTokens`](app/ConnectionOAuth/ConnectionTokens.php) renews an access token within a minute of expiring, before the downstream client uses it, and stores the refresh token the server rotated. Within a downstream session, a renewal gets only the session's time left (see [Downstream MCP servers](#downstream-mcp-servers)). Renewal is single-flight per Connection: a cache lock lets one request renew at a time, and the request that gets it re-reads the tokens first, so a request that waited uses the token another one just stored instead of replaying a used refresh token. Every other change to a Connection's sign-in (storing a new sign-in or a registered client, and `UpdateConnectionServer`) holds the same lock, [`SignInLock`](app/ConnectionOAuth/SignInLock.php), and works on the Connection as re-read once it has it, so none acts on, or writes back, credentials another change replaced. As a last guard, for a renewal that outlives its lock, renewed tokens are only stored while the Connection still has the server, settings and credentials the renewal started from. When the server refuses to renew, the sign-in is forgotten and the Connection needs sign-in; a renewal that fails for a reason that may pass (no answer, a server error, rate limiting) keeps the sign-in.
- **Reconnect**: `/connections/{connection}/connect` (`connections.connect`) starts a sign-in; the Connection page links to it as "Reconnect" when the Connection needs sign-in, and MCP clients are given it in needs-sign-in errors. For a Connection that doesn't sign in with OAuth it leads to the Connection's page, where its header or token can be replaced.
- Changing a custom server's URL forgets its tokens and registered client; changing the user's own OAuth app ends the sign-in, whose tokens were issued to the old one; switching away from OAuth clears both. A Connection left without a sign-in needs sign-in, and the page sends the user to sign in.

To test against real Notion and Linear locally, nothing needs registering: Nexus registers itself with each. GitHub needs an OAuth app: the deployment's (`NEXUS_GITHUB_CLIENT_ID` and `NEXUS_GITHUB_CLIENT_SECRET`) or the user's own, registered with the callback URL `{APP_URL}/oauth/callback`.

### Stars and their tools

A Star (`App\Models\Star`) is one MCP server endpoint owned by a user, bundling some of their Connections. Its URLs use its `public_id`, 20 random lowercase letters and digits (`/stars/{public_id}`, and `/mcp/{public_id}` for clients), never its numeric id, so they stay the same when it is renamed. Its `slug` comes from its name when it is created ("work", then "work-2" for the user's next "Work"), is unique among the user's Stars, names it in client configuration, and is kept when the Star is renamed. The user's plan caps how many they may create (2 on Free, none on Pro; see [Plans and billing](#plans-and-billing)).

Change Stars through the actions, which keep their rules:

- `App\Actions\CreateStar` holds a per-user cache lock while it counts and inserts, like `SaveNewConnection`; `limitMessage()` is what the user is told.
- `App\Actions\UpdateStarConnections` sets which Connections a Star includes, ignoring anyone else's. A Connection taken out loses its tool and prompt switches in that Star. Its `add()` adds one Connection and keeps the rest, as "Add to a Star" on a Connection's page does: it holds the Star's row while it reads the Star's Connections, so a change made meanwhile isn't undone, and returns false, changing nothing, when the Star already includes it or it isn't the Star's user's. Deleting a Connection removes it, and its switches, from every Star.
- `App\Actions\SwitchStarTools` gives tools of one of the Star's Connections the user's own switch (on or off), or takes it away (null). Given tool names it changes only those; without, it changes the whole Connection and drops switches for tools its server no longer lists.
- `App\Actions\SwitchStarPrompts` does the same for prompts.

A Star's Tools page groups each Connection's tools by risk, from what their servers declared ([`App\Enums\ToolRisk`](app/Enums/ToolRisk.php)): **Read-only** (`readOnlyHint: true`), **Writes** (not read-only and not declared destructive), **Destructive** (`destructiveHint: true`, unless it is read-only) and **Not declared** (neither hint stated). A group's switch and its reset call `SwitchStarTools` with the names of the group's tools the filter shows.

Clicking a tool's name on a Star's or a Connection's Tools page opens its details flyout. [`App\Stars\ToolDetailsReader`](app/Stars/ToolDetailsReader.php) reads what it shows in four queries, whatever the numbers: the tool's Connection owner's Stars that include the Connection, each with whether it has the tool on and whether the policy or the user's own switch decides, and the owner's latest five Activity entries for the tool's exposed name. `ConnectionTool::parameters()` reads the parameters from the tool's stored input schema (name, type, required, description), without opening up nested schemas: an object is `object`, an array of strings `string[]`, a list of types or `anyOf` choices `string | null`.

[`App\Stars\StarToolset`](app/Stars/StarToolset.php) is how anything reads a Star's tools:

```php
$toolset->tools($star);                                     // list<StarTool>: every tool of its Connections, on or off
$toolset->enabledTools($star);                              // list<StarTool>: only the ones that are on
$toolset->enabledTool($star, 'deepwiki__ask_wiki_question'); // ?StarTool: the tool with this exposed name, or null when it is unknown or off
$toolset->tool($star, 'deepwiki__ask_wiki_question');        // ?StarTool: the same, on or off
```

A `StarTool` holds the Connection, its catalog entry (`ConnectionTool`), the exposed name `{handle}__{tool}`, whether it is `enabled`, the user's own `switch` (null when the policy decides) and whether the Star `hasSiblings` for its Connection. `definition()` is the tool's JSON exactly as the server sent it, schemas and annotations untouched, with the exposed name in place of the server's. When the Star has a sibling of its Connection (another account of the same service), its `description()`, and so its definition's, starts with the account label and then the server's own description after a blank line: `From GitHub · octocat — use for: work`. A Star with one account of a service passes descriptions through unchanged.

A tool is on when the user switched it on in that Star and off when they switched it off. Otherwise the Star's new-tool policy (`App\Enums\NewToolPolicy`) decides, by the tool's annotations at the last refresh: `read_only` (the default) turns on only tools whose server declares `readOnlyHint: true`, so a tool that stops being read-only stops being on; `all` turns every tool on; `none` turns every tool off. Switches are stored by tool name, not catalog row, so they survive catalog refreshes, even a tool disappearing and coming back.

[`App\Stars\StarPrompts`](app/Stars/StarPrompts.php) reads a Star's prompts the same way: `prompts($star)` (on or off), `enabledPrompts($star)` and `prompt($star, 'deepwiki__summarize')`. A `StarPrompt` is shaped like a `StarTool`: the exposed name `{handle}__{prompt}`, whether it is `enabled`, the user's own `switch`, and a `definition()` that is the prompt's JSON as the server sent it, arguments untouched, renamed, with the account label leading its description when the Star has a sibling. Unlike a tool, a prompt is on unless the user switched it off, whatever the new-tool policy: it only gives the agent instructions, and the tools those lead to keep their own switches. Prompt switches are stored by name too.

The Stars page shows each Star as a card, with its last call and a sparkline of its recent calls. [`App\Stars\StarCallHistory`](app/Stars/StarCallHistory.php) reads them from Activity for every card in one query: `forStars($stars)` gives each Star's `StarCalls`, when it was last called (null when Activity has none) and its calls in each of the last 14 days, as 24-hour windows ending now, oldest first.

Above the cards, a getting-started checklist teaches the order of things: Connect a server, Create a Star, Set up a client, First call received. [`App\Stars\GettingStarted`](app/Stars/GettingStarted.php) ticks each step off from the user's own data, never from a click: they have a Connection; they have a Star; one of their Stars has a token, a connected app or its signed URL; one of their Stars has an Activity entry, or a token or connected app that has been used. It takes two queries however many Stars there are. The steps that aren't done link to the catalog on the Connections page (`#add-more`), the Create Star modal and the newest Star's client setup on its overview (`#setup`, preferring a Star that has a client). The checklist closes for good, on every device, when the user dismisses it or once every step is done, noted in `users.getting_started_closed_at`; from then on the page doesn't ask the database for it.

#### Adding a Connection from a Star

"Add a connection" on a Star's overview opens the catalog on the Connections page with the Star's public id in `?star=` (`App\Stars\ReturnToStar::addMoreUrl($star)`), so the user comes back to the Star with the new Connection in it. [`App\Stars\ReturnToStar`](app/Stars/ReturnToStar.php) honours only the signed-in user's own Star (`ReturnToStar::find()`); anything else is ignored, as if they came another way. While it is set, the Connections page, its connect dialog and the custom server page say "Adding to Work. You'll go back to Work when it's connected." and which tools start on in it, and carry the Star on to the custom server page and the sign-in route. A token or a custom server without sign-in or with a header is finished as soon as it's saved; an OAuth Connection keeps the Star in the session with its pending sign-in (`PendingSignIn::$returnTo`), never only in the query, so it survives the server's sign-in page and the callback finishes it (`ConnectionSignIn::returnTarget()`). Either way `ReturnToStar::finish()` decides where the user goes. Once the Connection's tools loaded, it is put in the Star through `UpdateStarConnections::add()`, so its tools start from the Star's new-tool policy, and the user goes back to the Star, which shows it saved, with the toast "GitHub added to Work.". When connecting fails (a refused token or header, tools that didn't load, a refused or failed sign-in), nothing is added: the user stays on the Connections page, still adding to the Star, with the error. There, Reconnect on a Connection that is in no Star yet adds it to the Star once signed in. Cancel, on the banner, in the connect dialog or on the custom server page, goes back to the Star without adding anything.

#### Caching a Star's lists

`StarToolset`, `StarPrompts` and `StarInstructions` keep what they work out in the cache through [`App\Stars\StarListCache`](app/Stars/StarListCache.php), so `tools/list`, `prompts/list`, looking up a tool to call or a prompt to get, and the instructions the Star's server works out for every request don't read the database again each time. Callers don't see it: they call the same methods. A database cache that refuses to store a list (the local cache store) is reported as `App\Exceptions\StarListsNotCached`, which names the Star, the list and the SQLSTATE but nothing the list holds, since that is the servers' catalogs; the list is served all the same.

- **What is cached.** For tools and prompts, the rows they are made from, as the database returns them: the Star's Connections (every column but the encrypted `secrets`), their catalogs and the Star's switches. Each read builds `StarTool`s and `StarPrompt`s from them afresh, with the Star's current new-tool policy. For instructions, the finished text, written from the Star's name and description as read when they are written, not from the copy the request loaded, which may be older than the version they are cached under. The cache never holds credentials, not even encrypted, so a `StarTool`'s Connection from `tools()` can't call its server (reading its secrets throws). `tool()` and `prompt()` find the entry in the cache and then read its Connection as stored, credentials and all, through the Star's own Connections: the one query a `tools/call` or `prompts/get` makes for its lookup, and the reason a Connection taken out of the Star is never called, whatever the cache holds.
- **Versions.** Every Star has a version in the cache (`stars.{id}.lists-version`), and its lists are cached under it. Nothing hunts down keys: a change gives the Star a new version, and the next request works its lists out afresh; copies under old versions are never read again. A version is random rather than counted, so one that expires or is evicted can never come back and revive old copies. Versions and lists last an hour, which only clears old copies and bounds how long a change made outside Nexus's code (by hand in the database, say) goes unseen.
- **After the commit.** `forget()` takes effect once the transaction in progress commits (at once outside one), so a request that reads the database while a change is being written can't cache the old state under the new version. A change that is rolled back changes nothing.
- **What forgets a Star's lists.** Its own changes (name, description, new-tool policy, anything: `Star::booted()`); any change to one of its Connections but its credentials (name, "use for" note, account, server, catalog refresh time, status), and its deletion through `DeleteConnection`, which forget every Star that includes it (`Connection::booted()`), so a Connection's sibling's labels follow too; and the writes Eloquent's events don't see, which call `forget()` themselves: `SwitchStarTools`, `SwitchStarPrompts`, `UpdateStarConnections`, `RefreshCatalog` (every refresh that stores, even one within the same second as the last) and `UpdateConnectionServer` when it empties a catalog. Anything new that changes what a list is made from must do the same.

A test that writes a catalog or switches straight to the database, instead of through these, sees the old lists until the version changes. To see the lists computed afresh, travel an hour, or change the Star (`$star->touch()`).

### Connectors

The Connections page lists the user's Connections first, then a gallery of **connectors** to add more (its "Add more" section, `/connections#add-more`, where the old `/connections/add` URL now leads): services with an official remote MCP server, such as GitHub, Notion and Linear. [`App\Connectors\ConnectorCatalog`](app/Connectors/ConnectorCatalog.php) loads them from [`resources/connectors`](resources/connectors), and a Connection made from one keeps its key in `connector_key` (`$connection->connector()` returns it; custom servers have none).

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
    - Optional: `scopes` (a list; without it the server's challenge decides), `preview` (`true` for servers still in preview), `requires_deployment_app` (`true` when users can't bring their own OAuth app, so the connector is only available once the deployment has one), `app` (required for `pre_registered`: `console_url` where users register an app, `instructions`, and an optional `manifest`), `token` for services that accept a token users create themselves (`console_url` where they create one, `instructions`, and optionally `header_name`, default `Authorization`, and `value_prefix`, default `"Bearer "`), `select_account` (`true` when the service's sign-in page shows an account chooser when asked with `prompt=select_account`, as GitHub's does, so a second account can be connected), and `profile_tool` (`name`, a tool on the server that says who Nexus is signed in as and takes no arguments, and `field`, the dot path in its result, structured content or JSON text, that names the account, as GitHub's `{"name": "get_me", "field": "login"}`).
    - Unknown fields are rejected, so a typo fails loudly, and credentials can't be added: the deployment's OAuth app for a connector comes from `NEXUS_{KEY}_CLIENT_ID` and `NEXUS_{KEY}_CLIENT_SECRET` (dashes in the key become underscores), which `config/nexus.php` reads for every JSON file. List the pair in `.env.example` for a connector whose server only accepts registered apps, as GitHub's is.

2. `resources/connectors/logos/{key}.svg`, the service's official logo from its brand assets: one `<svg>` element with a `viewBox` and no `width`, `height`, `class` or `style`, cleaned of metadata. It is inlined into pages, so it must not contain scripts, styles, event handlers, links or anything external. Use `fill="currentColor"` for a monochrome mark so it follows the text colour in dark mode, as GitHub's and Linear's do.

[`tests/Feature/Connectors/ConnectorCatalogTest.php`](tests/Feature/Connectors/ConnectorCatalogTest.php) validates every shipped definition and logo, so run it after adding one. The gallery's trademark notice names every connector automatically.

A user connects a gallery service with the methods its definition allows and this Nexus supports. For a token, [`App\Actions\ConnectWithToken`](app/Actions/ConnectWithToken.php) saves a header Connection to the connector's server through `SaveNewConnection`, with the token after its value prefix (so `Authorization: Bearer …`) stored encrypted, then loads its tools; if the server refuses the token, the Connection is removed and the user is told at once. For OAuth, [`App\Actions\ConnectWithOAuth`](app/Actions/ConnectWithOAuth.php) saves an OAuth Connection that needs sign-in and the page sends the user to sign in (see [Signing in with OAuth](#signing-in-with-oauth)). OAuth is preselected unless the user would have to register their own OAuth app, as for GitHub when the deployment has none: then the gallery offers their own token first, and their own app (with the callback URL to register) as the alternative.

### The Star MCP endpoint

Clients reach a Star at `POST /mcp/{public_id}` ([`routes/ai.php`](routes/ai.php)), a `laravel/mcp` web server ([`App\Mcp\Servers\StarServer`](app/Mcp/Servers/StarServer.php)). It speaks both protocol eras: 2026-07-28 clients connect with `server/discover` and must mirror the protocol version, method and tool name in the `MCP-Protocol-Version`, `Mcp-Method` and `Mcp-Name` headers (laravel/mcp checks them); 2025-11-25 clients connect with `initialize`. It is stateless, names itself `Nexus: {Star name}`, offers tools and prompts with `listChanged: false` and nothing else (other methods are "method not found"), and sends [`App\Stars\StarInstructions`](app/Stars/StarInstructions.php) as its instructions: the naming rule, the Star's description and one line per Connection with its handle and account label, kept to 2,048 characters without losing a Connection (the "use for" notes are shortened, then left out, those of Connections without a sibling first; then the description is shortened and every line cut to the same length, keeping at least its handle). When the Star has siblings, the instructions also say that their tools' descriptions start with the account.

- **Access.** `App\Http\Middleware\AuthenticateStarRequest` looks the Star up by its public id and lets the request in only with a credential for that Star, as its current access mode asks: in token mode one of its tokens as `Authorization: Bearer nxs_…`, in signed-URL mode its signed URL at its current version, in OAuth mode an access token Nexus issued to one of its connected apps (see [OAuth to Nexus](#oauth-to-nexus)). A credential of another mode is refused, so a Star that switched to a signed URL refuses its tokens, and the other way round. Anything else, including an unknown Star, gets a 401 with `WWW-Authenticate: Bearer realm="nexus"` (plus `error="invalid_token"` when a token was sent that didn't let it in) and a JSON-RPC error body saying how to authenticate in the Star's mode (an unknown Star is answered as one in token mode). In OAuth mode the challenge also names the Star's protected resource metadata and the scope, `resource_metadata="…/.well-known/oauth-protected-resource/mcp/{star}", scope="mcp:use"`, which is how clients start signing in. laravel/mcp's own challenge middleware is left off the route. The middleware attaches an `App\Mcp\StarCaller` to the request (the Star, how the client authenticated, the credential's name and its rate-limit key), which the container resolves for the server and its methods.
- **Rate limit.** `throttle:mcp` counts every request per credential (each token separately, each version of a Star's signed URL, so a rotated URL starts afresh, and each connected app, however often its access token is renewed): `NEXUS_CALLS_PER_MINUTE` (120) a minute, then 429. Laravel's limiter checks the count before adding to it, so requests that arrive at the same instant can overshoot the limit by a few; later ones are refused. Locally the count lives in the SQLite cache, which [`config/database.php`](config/database.php) sets up for concurrent writes (WAL, a 5-second busy timeout and immediate transactions); without that, overlapping requests fail with "database is locked". To check, send two requests at once and expect `200` twice:

    ```bash
    seq 2 | xargs -P 2 -I{} curl -s -o /dev/null -w '%{http_code}\n' https://nexus.test/mcp/<public_id> \
      -H "Authorization: Bearer $NEXUS_WORK_TOKEN" -H 'Content-Type: application/json' \
      -H 'Accept: application/json, text/event-stream' -H 'MCP-Protocol-Version: 2025-11-25' \
      -d '{"jsonrpc":"2.0","id":{},"method":"tools/list"}'
    ```
- **`tools/list`** returns the Star's tools that are on (`StarToolset::enabledTools()`, from the cache), each as its stored definition, in one page. Once it has answered, it queues a background refresh of any stale catalog (see [Keeping catalogs fresh](#keeping-catalogs-fresh)).
- **`tools/call`** forwards the arguments to the tool's Connection through the downstream client as the exact JSON object the client sent (read from the raw body; `{}` stays `{}`), and returns the server's result as the exact JSON it sent, `content`, `structuredContent` and `isError` included. Results are `App\Mcp\RawResult`s, which the server sends without decoding, setting only the members laravel/mcp adds to every result: `resultType`, any cache hints, and Nexus's server info in `_meta`. A tool that is off, or that none of the Star's Connections has, is JSON-RPC error `-32602`, like an unknown tool, and so is a call without a name or with arguments that aren't an object. The server checks those itself: it skips the `Request` laravel/mcp builds for its own tools, which would refuse bad arguments before the call could be recorded. When the downstream call fails, [`App\Mcp\ToolProxy`](app/Mcp/ToolProxy.php) answers with a tool error (`isError: true`) carrying Nexus's own message: a timeout says so, and a Connection that needs signing in again gets its absolute reconnect link, `/connections/{connection}/connect`, which starts an OAuth sign-in or, for a header or token, opens the Connection's page. An OAuth Connection's access token is renewed before the call when it has expired (see [Signing in with OAuth](#signing-in-with-oauth)). A server asking for more input (`resultType` other than `complete`) is answered the same way, since Nexus can't relay it yet. A call the server answers as an unknown tool queues a background refresh of the Connection's catalog.
- **`prompts/list`** returns the Star's prompts that are on (`StarPrompts::enabledPrompts()`), each as its stored definition, in one page. Like `tools/list`, once it has answered it queues a background refresh of any stale catalog.
- **`prompts/get`** forwards the arguments to the prompt's Connection as the exact JSON object the client sent and returns the server's result, its messages, as the exact JSON it sent, through [`App\Mcp\PromptProxy`](app/Mcp/PromptProxy.php). A prompt that is off or unknown, a request without a name and arguments that aren't an object are JSON-RPC error `-32602`, as for tools. Prompts have no error result, so when the downstream request fails, or the server asks for more input, the answer is JSON-RPC error `-32603` (which laravel/mcp sends with HTTP 500) carrying Nexus's own message, the reconnect link included when the Connection needs signing in again.

[`Tests\Support\StarClient`](tests/Support/StarClient.php) talks to a Star the way an MCP client does, through the HTTP kernel: `StarClient::for($star)->withToken($token)->callTool('wiki__search', '{"q":"x"}')` (or `->getPrompt('wiki__summarize', '{"page":"x"}')`), `->at($star->signedUrl())` for a client given only the signed URL, or `->speaking('2025-11-25')` for the older era. Pair it with the fake MCP server for the downstream side. [`Tests\Support\StarOAuthFlow`](tests/Support/StarOAuthFlow.php) plays an MCP client signing in to a Star in OAuth mode: `$clientId = StarOAuthFlow::register($star)`, then `$tokens = StarOAuthFlow::signIn($owner, $clientId)` (consent screen, approval and code exchange, with PKCE), and `withToken($tokens['access_token'])`. Each request starts afresh, as in its own PHP process: StarClient makes the OAuth guard forget its user, and token requests forget values memoized with `once()` (Passport caches clients that way). The OAuth server dates tokens by the system clock, so time travel doesn't expire them.

### Access modes, Star tokens and signed URLs

A Star's access mode (`App\Enums\StarAccessMode`) says how clients authenticate to it: `token` (the default), `signed_url` or `oauth`. The user picks it when creating the Star and changes it on the Star's Access page, which shows each mode's `label()` and `description()`. Change it only through `App\Actions\ChangeStarAccessMode`, which retires the credentials of the mode the Star leaves for good, so none of them works again even after switching back: leaving token mode revokes every token, leaving signed-URL mode rotates the signed URL, and leaving OAuth mode revokes every OAuth client that registered with the Star. It locks the Star's row while it switches, and `CreateStarToken` locks it too and refuses a Star that isn't in token mode, so a token can't slip in during a switch or from a page that was open before it. A new mode adds a case with its label and description, an arm in each `match` on the mode (PHPStan points them out), and what leaving it retires.

A Star token (`App\Models\StarToken`) is `nxs_` and 40 random letters and digits. Only its SHA-256 hash is stored, with its first 12 characters to show which one it is, so the plain token exists only in the response that created it. Create tokens through `App\Actions\CreateStarToken`, which holds a per-Star lock while it counts and inserts (`NEXUS_TOKENS_PER_STAR`, 10) and returns an `App\Stars\NewStarToken` with the plain text. The Star's Access page creates tokens in a modal that then shows the new one once, as the line that puts it in the variable the client setup reads (`ClientSetup::tokenExport($star, $token)`), with a way on to the overview's setup card (`#setup`), and forgets it when the modal closes or the user moves on. A link to the Access page with `new_token=<name>`, such as the setup's "Create a token for Cursor", opens that modal with the name filled in; anything but a plain name (letters, digits, spaces and `. _ - ( )`, at most 100 characters) is ignored. Revoking deletes the token. A client's use of a token updates its `last_used_at`.

A Star's signed URL (`$star->signedUrl()`) is its endpoint URL with its `signed_url_version` as `v` and a `signature`: one secret URL that works by itself, for clients that take only a URL, such as claude.ai. The signature is Laravel's signed-route HMAC over the path and query, without the scheme and host, so the URL keeps working behind a proxy or under another of the app's domains; the middleware checks it with `hasValidRelativeSignature()` and then that `v` is the Star's current version. `$star->rotateSignedUrl()` increments the version, so every earlier URL stops working at once; the Access page shows the URL to copy and rotates it after confirming. The signature uses the app key, so changing `APP_KEY` without keeping the old one in `APP_PREVIOUS_KEYS` invalidates every signed URL. `$star->clientUrl()` is the URL a client adds in the Star's mode: the signed URL in signed-URL mode, otherwise the endpoint URL.

[`App\Stars\ClientSetup`](app/Stars/ClientSetup.php) writes the copy-paste setup for adding a Star to one client (`App\Enums\McpClient`), for the Star's access mode. Clients know the Star as `nexus-{slug}`. In token mode it covers Claude Code, Codex, Cursor and Grok, and every snippet reads the token from the environment variable `NEXUS_{SLUG}_TOKEN` (dashes as underscores), so it never sits in a client's config file. In signed-URL mode each snippet holds only the signed URL, and claude.ai (Settings → Connectors → Add custom connector) comes first, since it takes nothing but a URL (`McpClient::supports()` leaves it out of token mode). OAuth mode is the same with the endpoint URL, and adds each client's login step: Connect in claude.ai, `claude mcp login nexus-{slug}` (or `/mcp` → Authenticate) for Claude Code, `codex mcp login nexus-{slug}`, Cursor's MCP settings or `cursor-agent mcp login nexus-{slug}`, and `/mcps` → `i` in Grok. A setup gives the config file its snippet goes in, or else an instruction for it (none for a command to run in a terminal), and in OAuth mode its `login`: an instruction, with a command when there is one. `prompt()` turns it into plain-language instructions an agent can follow (the endpoint, then each step with its file or command and snippet), holding no credential beyond what the snippets hold.

The overview's "Set up a client" card (`/stars/{star}#setup`) picks one client with a segmented picker and shows only its numbered steps; a token-mode Star's first step links to creating a token for that client, `/stars/{star}/access?new_token={client}`. The browser remembers the choice in the `nexus_setup_client` cookie (a client that can't reach the Star in its mode falls back to the first that can). Its last step, "Check it works", listens: it polls every five seconds for ten minutes from when it started (or until "Listen again"), and turns green once the Star hears from the client after the page was opened. In signed-URL mode only tool calls are recorded, so its hint and the prompt ask for a tool call rather than a tool list. [`App\Stars\StarCallers`](app/Stars/StarCallers.php) tells which clients have reached a Star from the names its credentials carry, matched by `McpClient::isNamedIn()` ("Claude Code (nexus-work)" is Claude Code, plain "Claude" is claude.ai): `clientsThatCalled()` reads the client names in its Activity, its used tokens and its connected apps, for the picker's green dots; `lastHeardFrom()` is the latest call, token use or connected-app use since a `watermark()` taken when the page opened (the id of the Star's latest Activity entry, and when each token and connected app was last used), counting anything except a name that names another client, since a signed URL names no one and a token may be named anything. Entries are told apart by id, so a call just after the page opened counts and one just before doesn't, even within one second; uses are stored to the second, so a token or app used in the second the page opened is seen at its next use.

### OAuth to Nexus

In OAuth mode a client signs in to Nexus itself, and the Star's owner approves it on a consent screen, so there is no secret to copy. Nexus is the OAuth authorization server, with [Laravel Passport](https://laravel.com/docs/passport), and every Star in OAuth mode is its own issuer, `{APP_URL}/oauth/stars/{star}`. A client registers with one Star, is approved for that Star, and its tokens open only that Star; Passport's authorization and token endpoints are shared, so the binding is Nexus's own.

1. **Discovery.** The Star's 401 names its protected resource metadata (RFC 9728), `/.well-known/oauth-protected-resource/mcp/{star}`: the Star's endpoint is the resource, its issuer the authorization server, `mcp:use` the scope. The issuer's metadata (RFC 8414) is at `/.well-known/oauth-authorization-server/oauth/stars/{star}`, and the same document at `/.well-known/openid-configuration/oauth/stars/{star}` for clients that look there: Passport's `/oauth/authorize` and `/oauth/token`, the Star's own registration endpoint, public clients only (`none`) and PKCE with S256. A Star in any other mode, or no Star, has none of these: they are 404s, and so is its registration endpoint.
2. **Registration** (RFC 7591) at `POST /oauth/stars/{star}/register` goes through laravel/mcp's handler, which registers a public PKCE client, then binds it to the Star in `star_oauth_clients` (`App\Models\StarOAuthClient`). Redirect URIs must be HTTPS, plain HTTP on a loopback address (`localhost`, `127.0.0.1`, `[::1]`, any port) or one of the custom schemes in [`config/mcp.php`](config/mcp.php) (`NEXUS_OAUTH_CUSTOM_SCHEMES`: `cursor`, `vscode`, `vscode-insiders`, `windsurf`, `zed`). Anyone may register, so registrations are limited per IP address (`NEXUS_OAUTH_REGISTRATIONS_PER_HOUR`, 20), and the Star's row is locked while one is bound, so the Star can't leave OAuth mode or be deleted halfway.
3. **Consent.** `/oauth/authorize` sends a guest to sign in first and back afterwards. The consent screen (`resources/views/oauth/authorize.blade.php`, Flux on the public layout) shows Nexus and the client side by side, asks "Allow {client} to use your Star “{Star}”?", lists what the client could do as icon rows (call the tools switched on in the Star, as the user, until revoked on its Access page), says where approving redirects to and who is signed in, with Deny and Approve in the card's footer. It offers Approve only when the client registered with one of the user's own Stars that still uses OAuth (`StarOAuthClient::approvableBy()`), and otherwise doesn't name the Star; `App\Http\Controllers\StarOAuth\ApproveAuthorizationController` replaces Passport's approval and checks the same again, so an approval sent by hand is a 403. Approving notes `approved_at` on the binding, which makes the client one of the Star's **connected apps**. "Not you?" posts the request's query to `oauth.switch-account`, which signs the user out (`App\Actions\SignOut`) and sends them back to `/oauth/authorize` with that query, so the guest is sent to sign in and returns to the same request afterwards, through the intended URL; it only ever leads back to the consent screen.
4. **Tokens** carry the `mcp:use` scope (also given to a client that asks for none), last an hour, and come with a refresh token that lasts 30 days. In the access middleware, Passport's `api` guard checks a token's signature, expiry and revocation; the middleware then requires the scope, the Star's owner as the token's user, and a connected app of this Star as its client, and notes when that app was last used. Calls are recorded in activity as `via = oauth` under the client's name (its first 100 characters).
5. **Revocation.** The Star's Access page lists its connected apps (name, where they return to, approved, last used) and revokes each after confirming. `App\Actions\RevokeOAuthClients` revokes a client with its access tokens, their refresh tokens and any unused authorization code, so it can't even renew its token and must register and be approved again. Leaving OAuth mode revokes every client of the Star, `App\Actions\DeleteStar` (the overview's Delete) revokes them before deleting the Star, and deleting an account revokes the clients of the user's Stars and every token issued to the user.

Passport's own routes are turned off (`Passport::ignoreRoutes()`); Nexus declares just the ones it uses: the token endpoint and the discovery and registration routes in [`routes/ai.php`](routes/ai.php) (no session or CSRF), the authorize, approve and deny routes in [`routes/web.php`](routes/web.php). The guard reports every token it refuses, and clients send expired ones every hour, so `bootstrap/app.php` doesn't report OAuth client errors (status below 500). Client IDs are UUIDs, and anyone may send anything as one; Postgres refuses to compare other text with a UUID column, so [`App\Auth\PassportClientRepository`](app/Auth/PassportClientRepository.php), bound in place of Passport's, treats any other ID as no client (`invalid_client`) instead of a server error. `passport:purge` runs daily, deleting tokens and codes that were revoked or expired over a week ago.

Passport signs access tokens with an RSA key pair: locally `storage/oauth-private.key` and `storage/oauth-public.key` (`php artisan passport:keys`, git-ignored), in production `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY` as secrets. Replacing the keys invalidates every access token issued so far; clients renew theirs with their refresh tokens.

To try it locally with a real client, put the Herd site's CA in the client's trust store (`NODE_EXTRA_CA_CERTS` for Node clients, `SSL_CERT_FILE` for Codex) and use a throwaway config, e.g. `CLAUDE_CONFIG_DIR=$(mktemp -d) claude mcp add --transport http --scope user nexus-phone '<url>'` then `claude mcp login nexus-phone`, or a Codex `CODEX_HOME` with the Star in `config.toml` and `codex mcp login nexus-phone`.

### Activity

Every `tools/call` and `prompts/get` that reaches a Star's server, refused ones included, writes one `App\Models\ActivityEntry` through `App\Actions\RecordActivity`: the user, Star and Connection, the kind (`tool` or `prompt`), the exposed name (null when the client sent none) and downstream name, the status (`ok`, `error` for failures and tool errors, `denied` for calls Nexus refused, `timeout` or `needs_auth`), how the client authenticated (`via`: `token` with the token's name, `signed_url` with no name, or `oauth` with the OAuth client's name) and the duration in milliseconds. It never stores arguments, results or any text from the server; the writer takes none. Requests stopped before the server (a missing or wrong credential, the rate limit, or headers that don't mirror a 2026-07-28 body) are not calls and aren't recorded.

Entries keep their Star and Connection ids until those are deleted, then null; deleting the user deletes their entries. A call can outlive them too: when the Star or Connection is deleted while the server answers, the client still gets the result and the entry is written with null in its place, and when the user is deleted meanwhile nothing is recorded.

The Activity page (`/activity`) reads like a log: the user's own entries, newest first and 25 to a page, grouped by day ("Today", "Yesterday", then the date), each day with how many calls in the range matched that whole day and how many of them weren't OK. Each row leads with an icon for its status, then the time, the exposed name, and "Star · Connection · client" underneath (the credential's name, or the access mode's label for a signed URL, and "prompt" for a prompt fetch); a call that wasn't OK says how it ended, and errors are tinted red. Times and days are in the viewer's timezone: the page asks the browser (`Intl`), keeps the answer in the session so the next visit renders local times straight away, ignores anything PHP doesn't know as a timezone, and shows UTC until then. Each day names its UTC offset (both of them on a day the clocks change), and each time's tooltip gives the full date with the offset at that moment. A denied call says why as far as the entry and the Star as it is now can tell (`App\Stars\DenialReasons`): "Denied · no name", "Denied · unknown tool" when none of the Star's Connections had the name, "Denied · tool off" only while that tool is still switched off in the Star, and plain "Denied" otherwise (switched on since, or refused for its arguments); likewise for prompts. Filters are chips for Star, Connection, status and kind, plus a 1h / 24h / 7d / 30d range (24h unless chosen); they live in the address bar (`?star={public_id}&connection={id}&status=error&kind=tool&range=7d`), and one that names none of the user's Stars or Connections, or no known status, kind or range, is ignored. When nothing matches, the page offers to clear the filters and, if earlier calls match, to show the last 30 days. The list polls every 5 seconds ("Live · updated 09:41") until the viewer pauses it. An entry whose Star is gone shows "Deleted Star". One whose Connection is gone shows "Deleted Connection", told apart from a call that named none of the Star's tools by its downstream name, which is only recorded with a Connection (`ActivityEntry::connectionWasDeleted()`).

Clicking a row opens its details: beside the log from the `xl` breakpoint, in a `flux:modal` flyout below it. The selected entry is in the address bar (`?entry={id}`), so a call can be linked, even one outside the range or page shown; an id that isn't one of the user's entries is ignored. The panel shows only what the entry stores: the status, the exact time in the viewer's timezone, the exposed and server's names, the Star and Connection (linked, or "Deleted" when they're gone), the client, how it came in (the access mode), the kind and the duration. Above them it offers the fix for how the call ended, if there is one:

- **Denied** for a tool or prompt that is switched off in the Star now (`DenialReasons::switchedOff()`): "Switch on in {Star}", which gives it the user's own switch through `SwitchStarTools` or `SwitchStarPrompts`, as the Star's own pages do, then says it's on; and a link to the Star's tools or prompts. A call refused for any other reason is explained, with the link when the Star still exists.
- **Needs sign-in**: "Reconnect {Connection}", the Connection's `connections.connect` route.
- **Timed out** or **Error**: a link to the Connection and "Refresh tools", which runs `RefreshCatalog` as the Connection's page does.

A fix finds the Star and Connection through the user's own records, so it can never touch another user's. Live polling redraws the panel in place: the selection is kept in the page's state, not the list, so new calls arriving never close or move it.

A Star's overview opens with its stats, worked out by [`App\Stars\StarStatsCounter`](app/Stars/StarStatsCounter.php) from the Star's own entries: its calls in the last 24 hours up to now (an entry exactly 24 hours old is out), those calls hour by hour for the sparkline, how many of them didn't end `ok` (every other status counts as an error) with their share, and its latest call however old, with the credential's name or, for a signed URL, the access mode. It counts them in one query, with a conditional count for each hour, so a busy Star's entries are never loaded and the same SQL runs on SQLite and Postgres.

`ActivityEntry` is mass-prunable: [`routes/console.php`](routes/console.php) schedules `model:prune` for it daily, which deletes entries older than `NEXUS_ACTIVITY_RETENTION_DAYS` (30) days in bulk, without loading them. To prune now, run `php artisan model:prune --model="App\Models\ActivityEntry"`.

### Terms, Privacy and Refund pages

The Terms of Service (`/terms`, `legal.terms`), Privacy Policy (`/privacy`, `legal.privacy`) and Refund Policy (`/refunds`, `legal.refunds`) are public: guests and signed-in users alike can read them, so their routes sit outside both the `guest` and `auth` groups. They are Livewire pages under `pages::legal.*`, each in an `<x-legal-page>`, and every public page's footer links all three. PayMongo's account activation asks for them too.

Their text is a plain-language draft. Until the owner has reviewed it with counsel and set `NEXUS_LEGAL_REVIEWED=true`, each page says "This draft is reviewed before Nexus takes payments." at the top. They give `NEXUS_CONTACT_EMAIL` as the address for questions, refunds and privacy requests. When you change a page's text, change its `updated` date too: it's the page's "Last updated" date.

### Plans and billing

Every account is on one of two plans, `App\Enums\Plan`:

| | Free | Pro |
| --- | --- | --- |
| Stars | 2 (`NEXUS_FREE_STARS`) | Unlimited |
| Connections | 10 (`NEXUS_FREE_CONNECTIONS`) | Unlimited |
| Tool calls | 3,000 a week (`NEXUS_FREE_TOOL_CALLS_PER_WEEK`) | Unlimited |
| Price | ₱0 | ₱499 a month or ₱4,999 a year |

The values live in [`config/nexus.php`](config/nexus.php) under `plans`: each plan's `stars`, `connections` and `tool_calls_per_week` (null means unlimited) and Pro's `prices`, in centavos as PayMongo counts them (49900 a month, 499900 a year). The Free limits can be changed with their variables, for tests and self-hosting. Read them through the enum: `Plan::Free->starLimit()`, `connectionLimit()`, `toolCallsPerWeek()`, `price(BillingPeriod::Year)` and `yearlySaving()` (what a year saves over twelve months). `App\Enums\BillingPeriod` is what one payment buys, a `Month` or a `Year`; its `after($moment)` adds one on the billing calendar (a month after a moment that is May 1 in Manila is June 1 in Manila, even while it is still April 30 in UTC) without running into the next month (a month after January 31 is the end of February), and returns it in the given moment's timezone, so it is stored as the right instant.

**Who is on Pro.** Pro is prepaid and never renews on its own. `users.pro_until` is when the user's Pro ends:

- `$user->plan()` is Pro while `pro_until` is in the future, and Free otherwise (null or past).
- `$user->proDaysLeft()` is how many days of Pro are left, a part of a day counting as a whole one, or null on Free; `isProEndingSoon()` is true in Pro's last `User::PRO_ENDING_SOON_DAYS` (7) days, when the Billing page and the plan card warn.
- `$user->nextProStart()` is when Pro paid for now starts: now, or the current `pro_until` if that is later, so paying early never loses days and months and years stack. `proUntilAfterPaying($period)` is that plus the period, the new `pro_until` a confirmed payment sets.
- In tests, `User::factory()->pro()` has a year of Pro left, `proEndingIn($days)` ends in that many days and `proEnded()` ended yesterday (`proEnded($days)`, that many days ago).

**Emails before and after Pro ends.** Pro never renews on its own, so Nexus emails each user before their Pro ends and once it has. `php artisan nexus:billing:remind` sends them, and [`routes/console.php`](routes/console.php) schedules it hourly, on one server.

- **"Pro ends soon"** (subject "Your Nexus Pro ends on {date}") goes to users whose `pro_until` is within the next 7 days (`User::PRO_ENDING_SOON_DAYS`). It says when Pro ends, what Free includes and that nothing is deleted, with an **Extend Pro** button to the Upgrade page.
- **"Pro has ended"** (subject "Your Nexus Pro has ended") goes to users whose `pro_until` passed in the last 48 hours (`ProReminder::ENDED_WITHIN_HOURS`): "You're back on Free", what that means, and **Extend Pro**. A Pro that ended longer ago gets nothing, so the first run after a deploy emails no backlog.
- **Once per end date.** Each email's column on `users`, `pro_ends_soon_emailed_for` or `pro_ended_emailed_for`, keeps the `pro_until` it went for. Extending Pro moves `pro_until`, so the new end date gets its own emails, and an email that hasn't gone yet waits for the new date.
- **Who is skipped:** users with no email address (they get the email if they add one while it's due), and anyone who never had Pro.
- [`App\Billing\ProReminders`](app/Billing/ProReminders.php) finds who is due (`due($reminder)`) and sends (`send($reminder)`), one `App\Enums\ProReminder` at a time. It claims each user with one conditional update (only while `pro_until` is unchanged and the email hasn't gone for it) before queueing the email, so overlapping runs, or a payment landing meanwhile, never send an email twice or for the wrong date.
- The email is `App\Notifications\ProReminderNotification`. It is queued, unlike a sign-in code, since nothing in it is secret. When the queue gets to it, it checks it still holds: it isn't sent if Pro was extended meanwhile, or if it is no longer due (a "Pro ends soon" reached after Pro ended, or a "Pro has ended" reached more than 48 hours after). Dates are in Philippine time (`BillingCalendar::date()`).
- **Trying it locally:** give a user Pro that ends in a few days, list who would be emailed with `--dry-run`, then send and run the queue. The emails land in `storage/logs/mail.log`.

    ```bash
    php artisan tinker --execute 'App\Models\User::firstWhere("email", "dev@example.com")->forceFill(["pro_until" => now()->addDays(5)])->save();'
    php artisan nexus:billing:remind --dry-run   # lists who would be emailed, sends nothing
    php artisan nexus:billing:remind
    php artisan queue:work --stop-when-empty     # or keep `composer dev` running
    ```

**Limits stop additions, never delete.** `$user->hasReachedStarLimit()` and `hasReachedConnectionLimit()` compare the user's count with their plan's limit, and Pro never reaches one. `App\Actions\CreateStar` and `App\Actions\SaveNewConnection` (which every way of adding a Connection goes through: the gallery, a custom server, and adding from a Star) refuse an addition at the limit under their per-user locks, with `limitMessage()` ("Free includes 2 Stars. Go Pro for more, or delete one you no longer use."). Nothing over a limit is deleted or disabled: an older account with more, or a Pro that ended, keeps every Star and Connection working and just can't add more. The Stars and Connections pages count against the limit on Free ("2 / 2 Stars", amber at it) and just count on Pro ("5 Stars").

**Billing time and money.** Billing dates show in Philippine time whatever the app's timezone, from `nexus.billing.timezone` (`Asia/Manila`): format them with `App\Billing\BillingCalendar::date($moment)` ("Oct 3, 2026") or `shortDate()` ("Oct 3"), or get the local moment with `local()`. The weekly tool-call limit resets on the same calendar. `App\Billing\Pesos::rounded($centavos)` writes a price ("₱4,999", "₱417") and `exact()` a receipt amount ("₱4,999.00").

Both billing pages follow their boards' frame: 880px wide and left-aligned, 56px in and 40px down from the main area on wide screens.

**The Billing page** (`/billing`, `billing.index`, `pages::billing.index`; boards P1, P4 and P6) is reached from the profile menu. Its current-plan card has three states:

- **Free:** "Free", "₱0 / month" and **Upgrade to Pro**.
- **Pro:** an "Active" pill, "Pro until {date} · N days left" and **Extend Pro**.
- **Pro's last 7 days:** an amber "Ends in N days" pill, "Pro until {date} · then you're back on Free", **Extend Pro** as the primary button and an amber footer.

Under the header, a row of `<x-usage-meter>`s shows the Stars and Connections against the plan's limits. The row lays out as many meters as it holds, side by side (stacked on a phone), so the weekly tool-calls meter goes in as a third. "Paid monthly" or "Paid yearly" beside "Pro" comes from the latest paid payment, once Nexus records payments; until then it isn't shown. The Payments card shows the compact empty state "No payments yet"; the list of paid payments goes in its body, keeping the empty state for none.

**The Upgrade page** (`/billing/upgrade`, `billing.upgrade`, `pages::billing.upgrade`; board P2) offers Free and Pro side by side with the Monthly / Yearly picker. Yearly is picked unless the link says `?period=month` (`?period=year` works too; anything else is Yearly), and switching changes Pro's price, its "Billed …" label and the line under the price. On Free, the Free card says "Your plan" and the heading is "Go Pro". On Pro the heading is "Extend Pro", saying what paying for the picked period does: "Adds a year to Pro: until {current end} becomes until {new end}." Until this Nexus takes payments, a callout, "Payments aren't set up on this Nexus yet.", stands in the Pro card where "Continue to payment" goes.

**The Pricing page** (`/pricing`, `pricing`, `pages::pricing`; board P7) is public, like the legal pages, so its route sits outside both the `guest` and `auth` groups, and every public page's header links it. It offers the same Free and Pro cards and Monthly / Yearly picker as the Upgrade page (Yearly first), then four questions, the last linking the Refund Policy. The headline's "two Stars" is the Free Star limit in words, so change it with `nexus.plans.free.stars`; every other price and limit comes from `nexus.plans`. **Start free** goes to sign-in for a guest and to the Stars page for a signed-in user. **Start with Pro** links to `billing.upgrade?period={picked}` for everyone: the `auth` middleware sends a guest to sign in and keeps that URL as the intended one, so signing in by any method lands on the Upgrade page with the period picked.

**The sidebar's plan card** (`<x-plan-card>`, board P8) shows on Free the user's Stars against the Free limit ("2 / 2 Stars", amber with a full bar at the limit, Atlas blue below it), "Go Pro for unlimited Stars, Connections and tool calls." and **Upgrade to Pro**; in Pro's last 7 days, "Pro ends in N days" and **Extend Pro**; and otherwise on Pro, nothing. Its states are branches of the component, checked in order, so a Free state that matters more (such as the week's tool calls used up) goes before the Stars one. It shows in the mobile sidebar menu too.

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

## Deploying to Laravel Cloud

Production runs on [Laravel Cloud](https://cloud.laravel.com) on the Starter plan. The code is ready for it:

- **Database.** Serverless Postgres. Cloud injects `DB_CONNECTION` and the connection details when the database is attached. Migrations and the test suite run on Postgres in CI.
- **Cache.** Laravel Valkey, with `CACHE_STORE=redis`, which Cloud sets when the cache is attached. It holds the cache locks (adding Connections, Stars and tokens, renewing OAuth tokens), the MCP rate limiter's counts and each Star's cached lists (see [Caching a Star's lists](#caching-a-stars-lists)), which tolerate eviction.
- **Queue.** A Flex managed queue. Cloud sets `QUEUE_CONNECTION=cloud` and configures the connection itself; the framework's `cloud` driver needs `aws/aws-sdk-php`, which is installed. Flex workers stop a job after 90 seconds, so keep every job well under that.
- **Scheduler.** On the App cluster, for the daily activity prune and catalog refresh and the hourly [Pro reminders](#plans-and-billing). Cloud wakes a sleeping environment for each task listed by `php artisan schedule:list`, which it reads at every deploy.
- **HTTPS.** On Laravel Cloud (`LARAVEL_CLOUD=1`) the framework trusts the edge's forwarded headers, so a request's scheme, host and address are the client's; anywhere else they are ignored. In production every URL Nexus writes is HTTPS whatever the request (`URL::forceHttps()` in `AppServiceProvider`), and `SESSION_SECURE_COOKIE=true` keeps cookies off plain HTTP.
- **Time limit.** Cloud ends a web request after about 60 seconds, so a downstream session (the handshake, any OAuth renewal and the call together) gives up after `NEXUS_DOWNSTREAM_CALL_TIMEOUT` (55 s), and the client gets Nexus's own timeout error instead of a gateway error. The [smoke test](#smoke-test) checks the real limit.

### What to create

Only these, at their smallest sizes, all in one region:

| Resource | Settings |
| --- | --- |
| Application | From `princejohnsantillan/nexus` on GitHub, with one environment, `production`, deploying the `main` branch. PHP 8.4, Node 22. |
| App cluster | The smallest Flex size, 1 replica, Scale-to-Zero on, Scheduler on, Octane off. |
| Database | Serverless Postgres 18, 0.25 compute units as both minimum and maximum, Scale-to-Zero on, the shortest backup retention offered. |
| Cache | Laravel Valkey, the smallest Flex size, Scale-to-Zero on if offered, the default eviction policy. |
| Managed queue | A standard queue named `default`: Flex, 256 MiB, at most 1 worker. |
| Edge network | The defaults: no bot categories, no rate limiting, Under Attack Mode off. MCP clients are bots, so blocking or challenging them breaks every Star. |

Create nothing else: no object storage bucket (attaching one injects global AWS settings), no worker cluster, no WebSockets and no custom domain.

Build commands:

```bash
composer install --no-dev --no-interaction --prefer-dist
npm ci --audit false
npm run build
php artisan optimize
```

Deploy command:

```bash
php artisan migrate --force
```

Don't add `queue:restart`, `optimize:clear` or `storage:link`: Cloud restarts the workers itself, and a deploy command's changes to the filesystem don't persist.

### Variables and secrets

Custom environment variables:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://{the environment's laravel.cloud domain}
SESSION_SECURE_COOKIE=true
NEXUS_CONTACT_EMAIL={the address people write to about their account, payments and privacy}
```

Leave `NEXUS_DEV_SIGN_IN` unset (the dev sign-in exists only locally anyway). Leave `NEXUS_LEGAL_REVIEWED` unset until the owner has reviewed the [legal pages](#terms-privacy-and-refund-pages) with counsel, then set it to `true` to take their draft note off. The `NEXUS_` limits and timeouts keep their defaults unless you set them.

Email sign-in stays hidden in production until a mail provider is set up, which is a later deploy step: set `MAIL_MAILER` to a mailer that delivers (such as `smtp`, with the provider's `MAIL_HOST`, `MAIL_USERNAME` and `MAIL_PASSWORD`, the password as a secret) and `MAIL_FROM_ADDRESS` to an address it may send from. With `log` or `array` the sign-in page and Settings don't offer it.

These are Cloud Secrets linked to the environment, never custom variables, files or commits:

| Secret | Value |
| --- | --- |
| `APP_KEY` | A new key from `php artisan key:generate --show` |
| `NEXUS_MASTER_KEY` | A new key from `php artisan nexus:master-key` (the `base64:…` value after `=`) |
| `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET` | The GitHub sign-in app |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | The Google sign-in OAuth client (optional: without it, Google sign-in is hidden) |
| `NEXUS_GITHUB_CLIENT_ID`, `NEXUS_GITHUB_CLIENT_SECRET` | The GitHub connector app (optional: without it, users connect GitHub with their own token or OAuth app) |
| `PASSPORT_PRIVATE_KEY`, `PASSPORT_PUBLIC_KEY` | A new RSA key pair for signing OAuth-to-Nexus access tokens, each the whole PEM text (see below) |

Cloud never shows a secret's value again, and without the master key no stored credential can be decrypted, so keep a copy of `NEXUS_MASTER_KEY` in a password manager and never change it. Changing `APP_KEY` signs everyone out and breaks every signed URL unless the old key goes in `APP_PREVIOUS_KEYS`. A custom variable overrides a secret of the same name, so if the environment already has an `APP_KEY` custom variable, delete it.

The CLI encrypts a secret before sending it. Pipe the value in, so it never appears in your shell history, then link the secrets and redeploy:

```bash
php artisan key:generate --show | cloud secret:create --name=APP_KEY --notes="Nexus production" --json -n
pbpaste | cloud secret:create --name=NEXUS_MASTER_KEY --notes="Nexus production" --json -n  # copied from your password manager
cloud secret:list --json -n                                  # the new secrets' IDs
cloud environment-secret:attach production {id} {id} -n
```

Generate Passport's key pair in a temporary directory rather than with `php artisan passport:keys`, which would replace your local pair, and delete it once both secrets exist:

```bash
cd "$(mktemp -d)"
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 -out oauth-private.key
openssl pkey -in oauth-private.key -pubout -out oauth-public.key
cloud secret:create --name=PASSPORT_PRIVATE_KEY --notes="Nexus production" --json -n < oauth-private.key
cloud secret:create --name=PASSPORT_PUBLIC_KEY --notes="Nexus production" --json -n < oauth-public.key
rm oauth-private.key oauth-public.key
```

### Runbook

Steps marked **owner** need the owner's own accounts. Anyone signed in to the Cloud CLI can do the rest.

1. **Owner:** install the Cloud CLI and sign in: `composer global require laravel/cloud-cli`, then `cloud auth` (it opens the browser). Cloud must be able to read `princejohnsantillan/nexus` on GitHub.
2. Create the application, its `production` environment and the resources in [What to create](#what-to-create). The Cloud dashboard's canvas shows each size. The CLI can do the same (`application:create`, `database-cluster:create`, `cache:create`, `managed-queue:create`, `instance:update`; read each one's `-h` first, and `cloud instance:sizes --json -n` and `cloud cache:types --json -n` list the sizes). Don't use `cloud ship`, which provisions its own defaults.
3. Set the build and deploy commands, the custom variables except `APP_URL`, and the `APP_KEY`, `NEXUS_MASTER_KEY`, `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY` secrets.
4. Deploy with `cloud deploy nexus production -n`, then follow it with `cloud deploy:monitor nexus production -n`. The first successful deploy gives the environment its `laravel.cloud` domain: set `APP_URL` to it.
5. **Owner:** register two OAuth apps at <https://github.com/settings/developers> (OAuth Apps → New OAuth App), each with `APP_URL` as its homepage URL:
    - **Nexus**, for signing in, with the callback URL `{APP_URL}/auth/github/callback`: its client ID and a new client secret are `GITHUB_CLIENT_ID` and `GITHUB_CLIENT_SECRET`.
    - **Nexus GitHub connector**, for connecting GitHub, with the callback URL `{APP_URL}/oauth/callback`: `NEXUS_GITHUB_CLIENT_ID` and `NEXUS_GITHUB_CLIENT_SECRET`.

    Store the four values as secrets (`pbpaste | cloud secret:create --name=GITHUB_CLIENT_SECRET --json -n`, and so on) and link them.

    For Google sign-in (optional), create an OAuth client ID of the "Web application" type at <https://console.cloud.google.com/apis/credentials>, with the authorized redirect URI `{APP_URL}/auth/google/callback`, and store its client ID and secret as `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` the same way.
6. Redeploy and monitor it, then check what the app sees:

    ```bash
    cloud tinker production -n --code='echo config("app.url"), " ", DB::connection()->getDriverName(), " ", config("cache.default"), " ", config("queue.default"), PHP_EOL; cache()->put("deploy-check", "ok", 60); echo cache()->get("deploy-check"), PHP_EOL;'
    ```

    It prints the HTTPS `APP_URL`, `pgsql`, `redis` and `cloud`, then `ok`. Then check that Passport can read its keys (a multi-line secret is easy to mangle):

    ```bash
    cloud tinker production -n --code='echo openssl_pkey_get_private(config("passport.private_key")) && openssl_pkey_get_public(config("passport.public_key")) ? "passport keys ok" : "passport keys unreadable", PHP_EOL;'
    ```
7. Run the smoke test and record the results on the deploy's pull request.

After changing a variable, a secret or an attached resource, redeploy: Cloud applies them only to new deploys.

### Smoke test

On the deployed URL:

1. Sign in with GitHub.
2. Add a custom MCP server: DeepWiki, `https://mcp.deepwiki.com/mcp`, no sign-in. Its three tools load.
3. Create a Star with DeepWiki in token mode, switch on `deepwiki__read_wiki_structure` on its Tools page (DeepWiki doesn't mark its tools read-only, so they start off), and create a token on its Access page.
4. Call the tool from a real client: add the Star to Claude Code with the snippet on its overview and ask about a repository's wiki, or use the MCP Inspector CLI:

    ```bash
    npx @modelcontextprotocol/inspector --cli "$APP_URL/mcp/{public_id}" --transport http \
      --header "Authorization: Bearer $NEXUS_WORK_TOKEN" \
      --method tools/call --tool-name deepwiki__read_wiki_structure --tool-arg repoName=laravel/framework
    ```

    The call appears on the Activity page.
5. Confirm the time limit with a deliberately slow server. The MCP reference server has a tool that waits as long as it is told. Run it behind any public HTTPS tunnel (ngrok, Herd's Expose, cloudflared):

    ```bash
    PORT=3917 npx -y @modelcontextprotocol/server-everything streamableHttp
    ngrok http 3917
    ```

    Add it as a custom MCP server (handle `slow`, URL `https://{tunnel}/mcp`, no sign-in), include it in the Star and switch on `slow__trigger-long-running-operation`. Call it with curl, since MCP clients often give up after 60 seconds on their own and curl doesn't:

    ```bash
    curl -s -w '\nHTTP %{http_code} in %{time_total}s\n' "$APP_URL/mcp/{public_id}" \
      -H "Authorization: Bearer $NEXUS_WORK_TOKEN" -H 'Content-Type: application/json' \
      -H 'Accept: application/json, text/event-stream' -H 'MCP-Protocol-Version: 2025-11-25' \
      -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"slow__trigger-long-running-operation","arguments":{"duration":50,"steps":1}}}'
    ```

    With `"duration":50` the server's result comes back after about 50 seconds. With `70`, a tool error ("The server took too long to answer, so Nexus stopped waiting.") comes back about 55 seconds after the request: Nexus gave up inside the platform's limit, handshake included. If the edge answers with a 5xx before then, the limit is lower: set `NEXUS_DOWNSTREAM_CALL_TIMEOUT` a few seconds under it. To measure the limit itself, set `NEXUS_DOWNSTREAM_CALL_TIMEOUT=120`, redeploy, call with `100` and note when and how the request ends, then remove the variable and redeploy. Delete the `slow` Connection afterwards.

## Continuous integration

[`.github/workflows/qa.yml`](.github/workflows/qa.yml) runs on every push and pull request targeting `main` or `stars`. Its `qa` job installs the PHP and Node dependencies, builds the front end and runs `composer qa` on PHP 8.4. Its `postgres` job runs the test suite again on Postgres 18, the database production uses.
