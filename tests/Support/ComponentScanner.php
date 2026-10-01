<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Str;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Finds calls to banned functions in Livewire component class files.
 *
 * Multi-file components return an anonymous class from a plain PHP file under
 * resources/views, so architecture rules never load them. This scanner reads
 * their tokens instead.
 */
final readonly class ComponentScanner
{
    /**
     * @param  list<string>  $bannedFunctions
     */
    public function __construct(private array $bannedFunctions) {}

    /**
     * Every component PHP file under the directory (Blade views excluded).
     *
     * @return list<string>
     */
    public static function componentFiles(string $directory): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if ($file->isFile() && str_ends_with($path, '.php') && ! str_ends_with($path, '.blade.php')) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The banned functions called in the given PHP source, in order of appearance.
     *
     * @return list<string>
     */
    public function bannedCallsIn(string $source): array
    {
        $tokens = PhpToken::tokenize($source);
        $imports = $this->functionImports($tokens);
        $calls = [];

        foreach ($tokens as $index => $token) {
            $name = $this->calledFunctionName($tokens, $index, $imports);

            if ($name !== null && in_array($name, $this->bannedFunctions, true)) {
                $calls[] = $name;
            }
        }

        return $calls;
    }

    /**
     * The functions imported with `use function`, keyed by the name they are called by.
     *
     * Both sides are lowercase and the imported name has no leading backslash, so
     * `use function dd as debug;` maps "debug" to "dd".
     *
     * @param  list<PhpToken>  $tokens
     * @return array<string, string>
     */
    private function functionImports(array $tokens): array
    {
        $imports = [];

        foreach ($tokens as $index => $token) {
            if (! $token->is(T_USE) || $this->neighbour($tokens, $index, 1)?->is(T_FUNCTION) !== true) {
                continue;
            }

            $prefix = '';
            $name = null;
            $alias = null;
            $expectsAlias = false;

            for ($i = $index + 1; isset($tokens[$i]) && $tokens[$i]->text !== ';'; $i++) {
                $current = $tokens[$i];

                if ($current->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                    if ($expectsAlias) {
                        $alias = $current->text;
                        $expectsAlias = false;
                    } else {
                        $name = $current->text;
                    }
                } elseif ($current->is(T_AS)) {
                    $expectsAlias = true;
                } elseif ($current->text === '{') {
                    $prefix = trim((string) $name, '\\').'\\';
                    $name = null;
                } elseif ($current->text === ',' || $current->text === '}') {
                    $this->addImport($imports, $prefix, $name, $alias);
                    $name = null;
                    $alias = null;
                }
            }

            $this->addImport($imports, $prefix, $name, $alias);
        }

        return $imports;
    }

    /**
     * Record one imported function under the name it is called by.
     *
     * @param  array<string, string>  $imports
     */
    private function addImport(array &$imports, string $prefix, ?string $name, ?string $alias): void
    {
        if ($name === null) {
            return;
        }

        $imported = strtolower(ltrim($prefix.$name, '\\'));
        $calledAs = strtolower($alias ?? Str::afterLast($imported, '\\'));

        $imports[$calledAs] = $imported;
    }

    /**
     * The function called at this token, if the token starts a function call.
     *
     * Global functions come back without a namespace; calls through a `use function`
     * import resolve to the imported function.
     *
     * @param  list<PhpToken>  $tokens
     * @param  array<string, string>  $imports
     */
    private function calledFunctionName(array $tokens, int $index, array $imports): ?string
    {
        $token = $tokens[$index];

        // eval, exit and die are language constructs with their own tokens.
        if ($token->is([T_EVAL, T_EXIT])) {
            return strtolower($token->text);
        }

        if (! $token->is([T_STRING, T_NAME_FULLY_QUALIFIED])) {
            return null;
        }

        if ($this->neighbour($tokens, $index, 1)?->text !== '(') {
            return null;
        }

        $previous = $this->neighbour($tokens, $index, -1);

        if ($previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST]) === true) {
            return null;
        }

        if ($token->is(T_NAME_FULLY_QUALIFIED)) {
            return strtolower(ltrim($token->text, '\\'));
        }

        return $imports[strtolower($token->text)] ?? strtolower($token->text);
    }

    /**
     * The nearest token in the given direction that is not whitespace or a comment.
     *
     * @param  list<PhpToken>  $tokens
     */
    private function neighbour(array $tokens, int $index, int $direction): ?PhpToken
    {
        for ($i = $index + $direction; isset($tokens[$i]); $i += $direction) {
            if (! $tokens[$i]->isIgnorable()) {
                return $tokens[$i];
            }
        }

        return null;
    }
}
