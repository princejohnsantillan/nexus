<?php

declare(strict_types=1);

namespace Tests\Support;

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
        $calls = [];

        foreach ($tokens as $index => $token) {
            $name = $this->calledFunctionName($tokens, $index);

            if ($name !== null && in_array($name, $this->bannedFunctions, true)) {
                $calls[] = $name;
            }
        }

        return $calls;
    }

    /**
     * The global function called at this token, if the token starts a function call.
     *
     * @param  list<PhpToken>  $tokens
     */
    private function calledFunctionName(array $tokens, int $index): ?string
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

        return strtolower(ltrim($token->text, '\\'));
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
