<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Enums\StarAccessMode;
use App\Models\Star;
use App\Models\StarToken;
use Illuminate\Http\Request;
use LogicException;

/**
 * Who is calling a Star's MCP endpoint: the Star, and the credential the
 * request authenticated with. The access middleware attaches it to the
 * request; the Star's server, its methods, the rate limiter and activity
 * entries read it from there.
 */
final readonly class StarCaller
{
    /**
     * @param  StarAccessMode  $via  How the client authenticated.
     * @param  string|null  $clientName  What the user named the credential, such as a token's name.
     * @param  string  $rateLimitKey  What calls are counted by for the rate limit: one credential.
     */
    public function __construct(
        public Star $star,
        public StarAccessMode $via,
        public ?string $clientName,
        public string $rateLimitKey,
    ) {}

    /**
     * A client calling with one of the Star's tokens.
     */
    public static function withToken(Star $star, StarToken $token): self
    {
        return new self($star, StarAccessMode::Token, $token->name, "star-token:{$token->id}");
    }

    /**
     * The caller the access middleware attached to the request.
     *
     * @throws LogicException when the request didn't pass through the access middleware
     */
    public static function of(Request $request): self
    {
        $caller = $request->attributes->get(self::class);

        return $caller instanceof self ? $caller : throw new LogicException('The request has not been authenticated for a Star.');
    }

    public function attachTo(Request $request): void
    {
        $request->attributes->set(self::class, $this);
    }
}
