<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Functions that must never appear in application code.
 *
 * Architecture tests apply these lists to classes; the component scan applies
 * all() to Livewire component files, which architecture rules cannot see.
 */
final class BannedFunctions
{
    /**
     * Debugging helpers that must not ship.
     *
     * @var list<string>
     */
    public const array DEBUG = [
        'dd',
        'ddd',
        'dump',
        'ray',
        'ds',
        'trap',
        'var_dump',
        'var_export',
        'print_r',
        'debug_zval_dump',
        'debug_print_backtrace',
        'phpinfo',
        'xdebug_break',
        'xdebug_var_dump',
        'die',
        'exit',
    ];

    /**
     * The functions banned by Pest's security preset (Pest\ArchPresets\Security).
     *
     * @var list<string>
     */
    public const array SECURITY = [
        'md5',
        'sha1',
        'uniqid',
        'rand',
        'mt_rand',
        'tempnam',
        'str_shuffle',
        'shuffle',
        'array_rand',
        'eval',
        'exec',
        'shell_exec',
        'system',
        'passthru',
        'create_function',
        'unserialize',
        'extract',
        'mb_parse_str',
        'dl',
        'assert',
    ];

    /**
     * The functions banned by Pest's Laravel preset (Pest\ArchPresets\Laravel).
     *
     * @var list<string>
     */
    public const array LARAVEL = [
        'dd',
        'ddd',
        'dump',
        'env',
        'exit',
        'ray',
    ];

    /**
     * Every banned function, once.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values(array_unique([...self::DEBUG, ...self::SECURITY, ...self::LARAVEL]));
    }
}
