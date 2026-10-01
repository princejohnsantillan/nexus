<?php

declare(strict_types=1);

namespace App\Rules;

use App\Exceptions\OutboundRequestBlocked;
use App\Outbound\OutboundGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A URL the outbound guard lets Nexus connect to: HTTPS, on a host that
 * resolves only to public addresses. It fails with the guard's own message,
 * e.g. "Only HTTPS URLs are allowed."
 */
class McpServerUrl implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('That is not a valid URL.'));

            return;
        }

        try {
            resolve(OutboundGuard::class)->check($value);
        } catch (OutboundRequestBlocked $blocked) {
            $fail($blocked->getMessage());
        }
    }
}
