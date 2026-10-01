# Nexus

- Read the Conventions section of `README.md` before writing code. It is the source of truth for structure, UI, tests and tooling.
- Every screen is a Livewire multi-file page component under `resources/views/pages`. Generate it with `php artisan make:livewire pages::area.screen` and route it with `Route::livewire('/path', 'pages::area.screen')->name('area.screen')`.
- Components hold only screen state. Put the real work in named classes under `App\` (actions, services, models).
- Use Flux free components only. Never use Flux Pro components.
- Give every list an empty state with `<x-empty-state>`.
- Write tests with Pest through a public seam: HTTP requests, or Livewire pages tested by name with `Livewire::test('pages::…')`. Fake only the network edge.
- Before finishing, run `composer fix`, then `composer qa`, and make it pass. Never add a PHPStan baseline, an ignore comment or a cast just to silence an error.
- The repository is public. Never commit `.env`, databases, keys or tokens.
