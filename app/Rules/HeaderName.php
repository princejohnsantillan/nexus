<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * The name of a header Nexus sends to sign in: a valid HTTP header name that
 * isn't one Nexus or the HTTP client sets itself for MCP requests.
 */
class HeaderName implements ValidationRule
{
    /**
     * An HTTP field name (RFC 9110 token).
     */
    private const string TOKEN_PATTERN = "/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/";

    /**
     * Headers Nexus or the HTTP client set themselves, lowercased.
     *
     * @var list<string>
     */
    private const array RESERVED = [
        'accept',
        'connection',
        'content-length',
        'content-type',
        'expect',
        'host',
        'keep-alive',
        'proxy-authorization',
        'te',
        'trailer',
        'transfer-encoding',
        'upgrade',
    ];

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::TOKEN_PATTERN, $value) !== 1) {
            $fail(__('Enter a header name such as Authorization or X-API-Key, without spaces or a colon.'));

            return;
        }

        $name = strtolower($value);

        if (in_array($name, self::RESERVED, true) || str_starts_with($name, 'mcp-')) {
            $fail(__('Nexus sets the :header header itself. Choose another header.', ['header' => $value]));
        }
    }
}
