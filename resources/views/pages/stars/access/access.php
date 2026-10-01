<?php

declare(strict_types=1);

use App\Actions\CreateStarToken;
use App\Models\Star;
use App\Models\StarToken;
use App\Stars\ClientSetup;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Title('Star access')] class extends Component
{
    public Star $star;

    /**
     * The name of the next token.
     */
    public string $name = '';

    /**
     * The token just created, shown once in a modal and forgotten when it closes.
     */
    #[Locked]
    public ?string $newToken = null;

    /**
     * The Star's tokens, newest first.
     *
     * @return Collection<int, StarToken>
     */
    #[Computed]
    public function tokens(): Collection
    {
        return $this->star->tokens()->latest('id')->get();
    }

    /**
     * What to tell the user when the Star can't have another token, or null when it can.
     */
    #[Computed]
    public function limitMessage(): ?string
    {
        return $this->star->hasReachedTokenLimit() ? CreateStarToken::limitMessage() : null;
    }

    /**
     * The environment variable the setup snippets read the token from.
     */
    #[Computed]
    public function tokenVariable(): string
    {
        return ClientSetup::tokenVariable($this->star);
    }

    public function create(CreateStarToken $createStarToken): void
    {
        $this->name = trim($this->name);

        $this->validate(['name' => ['required', 'string', 'max:100']]);

        $this->newToken = $createStarToken->handle($this->star, $this->name)->plainTextToken;

        $this->reset('name');
        $this->forgetTokens();

        $this->dispatch('modal-show', name: 'new-token', scope: $this->getId());
    }

    /**
     * Forget the token just created, once its modal closes.
     */
    public function forgetNewToken(): void
    {
        $this->newToken = null;
    }

    public function revoke(int $tokenId): void
    {
        $token = $this->star->tokens()->findOrFail($tokenId);
        $token->delete();

        $this->forgetTokens();

        $this->dispatch('modal-close', name: "revoke-token-{$tokenId}", scope: $this->getId());
        Flux::toast(variant: 'success', text: __('Revoked :name. Clients using it can no longer reach this Star.', ['name' => $token->name]));
    }

    private function forgetTokens(): void
    {
        unset($this->tokens, $this->limitMessage);
    }
};
