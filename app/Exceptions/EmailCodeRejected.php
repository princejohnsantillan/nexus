<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A one-time email code didn't work: it was wrong, expired, tried too many
 * times, or there is no code to check it against (it was used, or never
 * sent).
 *
 * The message is Nexus's own and safe to show the user, and says what to do
 * next. It never contains the code.
 */
final class EmailCodeRejected extends RuntimeException
{
    /**
     * The code didn't match the one sent. The code still works for the tries
     * left, if any.
     */
    public static function wrong(int $triesLeft): self
    {
        if ($triesLeft < 1) {
            return new self(__('That code didn\'t match, and it can\'t be tried again. Send a new code.'));
        }

        return new self(trans_choice(
            'That code didn\'t match. Check the latest email, or send a new code. :count try left.|That code didn\'t match. Check the latest email, or send a new code. :count tries left.',
            $triesLeft,
        ));
    }

    public static function expired(): self
    {
        return new self(__('That code has expired. Send a new code.'));
    }

    public static function triedTooOften(): self
    {
        return new self(__('That code was tried too many times. Send a new code.'));
    }

    /**
     * There is no code to check: it was used already, or none was sent.
     */
    public static function missing(): self
    {
        return new self(__('That code can\'t be used any more. Send a new code.'));
    }
}
