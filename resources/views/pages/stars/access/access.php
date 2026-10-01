<?php

declare(strict_types=1);

use App\Actions\ChangeStarAccessMode;
use App\Actions\CreateStarToken;
use App\Enums\StarAccessMode;
use App\Models\Star;
use App\Models\StarToken;
use App\Stars\ClientSetup;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Title('Star access')] class extends Component
{
    public Star $star;

    /**
     * The access mode chosen, the Star's own until the user picks another.
     */
    public string $accessMode = '';

    /**
     * The name of the next token.
     */
    public string $name = '';

    /**
     * The token just created, shown once in a modal and forgotten when it closes.
     */
    #[Locked]
    public ?string $newToken = null;

    public function mount(): void
    {
        $this->accessMode = $this->star->access_mode->value;
    }

    /**
     * The access mode chosen, or null when it isn't one.
     */
    #[Computed]
    public function chosenMode(): ?StarAccessMode
    {
        return StarAccessMode::tryFrom($this->accessMode);
    }

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

    /**
     * Switch the Star to the chosen access mode, retiring the credentials
     * of the mode it leaves.
     */
    public function changeAccessMode(ChangeStarAccessMode $changeStarAccessMode): void
    {
        $this->validate(['accessMode' => ['required', Rule::enum(StarAccessMode::class)]]);

        $accessMode = StarAccessMode::from($this->accessMode);
        $changeStarAccessMode->handle($this->star, $accessMode);

        $this->forgetTokens();

        $this->dispatch('modal-close', name: 'change-access-mode', scope: $this->getId());
        Flux::toast(variant: 'success', text: match ($accessMode) {
            StarAccessMode::Token => __('This Star now uses tokens. Create one below for each client.'),
            StarAccessMode::SignedUrl => __('This Star now uses a signed URL. Copy it below and give it to your clients.'),
        });
    }

    /**
     * Give the Star a new signed URL, so the old one stops working. A page
     * opened before the Star switched away from signed-URL mode rotates
     * nothing.
     */
    public function rotateSignedUrl(): void
    {
        if ($this->star->access_mode !== StarAccessMode::SignedUrl) {
            Flux::toast(variant: 'warning', text: __('This Star no longer uses a signed URL. Reload the page to see how clients reach it.'));

            return;
        }

        $this->star->rotateSignedUrl();

        $this->dispatch('modal-close', name: 'rotate-signed-url', scope: $this->getId());
        Flux::toast(variant: 'success', text: __('Rotated. The old URL no longer works, so give your clients the new one.'));
    }

    private function forgetTokens(): void
    {
        unset($this->tokens, $this->limitMessage);
    }
};
