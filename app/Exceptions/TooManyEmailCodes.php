<?php

declare(strict_types=1);

namespace App\Exceptions;

use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use RuntimeException;

/**
 * No code can be sent yet: the address had one moments ago, or the address
 * or the network asking has had as many as an hour allows.
 *
 * The message is Nexus's own and safe to show the user. It says how long to
 * wait, and never whether the address has an account.
 */
final class TooManyEmailCodes extends RuntimeException
{
    /**
     * @param  int  $retryAfter  Seconds until a code can be sent.
     */
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct(__('You can ask for another code in :wait.', [
            'wait' => CarbonInterval::seconds(max(1, $retryAfter))->cascade()->forHumans(['parts' => 1, 'options' => CarbonInterface::CEIL]),
        ]));
    }
}
