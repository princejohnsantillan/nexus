<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The outbound guard refused to let a request leave Nexus.
 *
 * The message is written by Nexus and safe to show the user: it names the
 * host at most, never the full URL or the addresses it resolved to.
 */
final class OutboundRequestBlocked extends RuntimeException {}
