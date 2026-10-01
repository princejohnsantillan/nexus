<?php

declare(strict_types=1);

use Tests\Support\BannedFunctions;
use Tests\Support\ComponentScanner;

dataset('component files', function (): array {
    $views = dirname(__DIR__, 2).'/resources/views';
    $files = ComponentScanner::componentFiles($views);

    return array_combine(
        array_map(fn (string $path): string => substr($path, strlen($views) + 1), $files),
        array_map(fn (string $path): array => [$path], $files),
    );
});

it('keeps banned functions out of component files', function (string $path): void {
    $scanner = new ComponentScanner(BannedFunctions::all());

    expect($scanner->bannedCallsIn((string) file_get_contents($path)))->toBe([]);
})->with('component files');

it('scans component class files and skips Blade views', function (): void {
    $views = dirname(__DIR__, 2).'/resources/views';

    expect(ComponentScanner::componentFiles($views))
        ->toContain($views.'/pages/stars/index/index.php')
        ->not->toContain($views.'/pages/stars/index/index.blade.php');
});

it('catches a dd() call in a component file', function (): void {
    $directory = sys_get_temp_dir().'/component-scan-'.bin2hex(random_bytes(6));
    mkdir($directory.'/debugging', recursive: true);
    file_put_contents($directory.'/debugging/debugging.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        use Livewire\Component;

        return new class extends Component
        {
            public function mount(): void
            {
                dd($this);
            }
        };
        PHP);
    file_put_contents($directory.'/debugging/debugging.blade.php', '<div>{{ dd($this) }}</div>');

    $scanner = new ComponentScanner(BannedFunctions::all());
    $files = ComponentScanner::componentFiles($directory);

    expect($files)->toBe([$directory.'/debugging/debugging.php'])
        ->and($scanner->bannedCallsIn((string) file_get_contents($files[0])))->toBe(['dd']);

    unlink($directory.'/debugging/debugging.php');
    unlink($directory.'/debugging/debugging.blade.php');
    rmdir($directory.'/debugging');
    rmdir($directory);
});

it('catches every banned function', function (string $function): void {
    $scanner = new ComponentScanner(BannedFunctions::all());

    expect($scanner->bannedCallsIn("<?php {$function}('x');"))->toBe([$function])
        ->and($scanner->bannedCallsIn("<?php \\{$function}('x');"))->toBe([$function]);
})->with(BannedFunctions::all());

it('catches banned functions called through a use function import', function (): void {
    $scanner = new ComponentScanner(BannedFunctions::all());

    $source = <<<'PHP'
        <?php

        use Livewire\Component;

        use function dd as debug;
        use function \exec as run, var_dump;
        use function Illuminate\Support\{tap, value as get};

        return new class extends Component
        {
            public function probe(): never
            {
                run('ls');
                var_dump($this);
                $dumper = debug(...);
                debug($this);
            }
        };
        PHP;

    expect($scanner->bannedCallsIn($source))->toBe(['exec', 'var_dump', 'dd', 'dd']);
});

it('resolves a namespaced function imported under a banned name', function (): void {
    $scanner = new ComponentScanner(BannedFunctions::all());

    $source = <<<'PHP'
        <?php

        use function App\Support\inspect as dump;

        dump('x');
        PHP;

    expect($scanner->bannedCallsIn($source))->toBe([]);
});

it('ignores methods, declarations and strings that share a banned name', function (): void {
    $scanner = new ComponentScanner(BannedFunctions::all());

    $source = <<<'PHP'
        <?php

        $this->dump();
        $this?->exec();
        Str::extract();
        $label = 'dd()';
        // dd($label);
        /* exec('ls'); */
        $callback = fn () => $label;
        foo(system: true);

        function md5(): void {}
        PHP;

    expect($scanner->bannedCallsIn($source))->toBe([]);
});
