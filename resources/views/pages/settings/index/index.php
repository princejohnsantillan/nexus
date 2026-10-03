<?php

declare(strict_types=1);

use App\Actions\DeleteAccount;
use App\Models\SignInIdentity;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Title('Settings')] class extends Component
{
    /**
     * What the user typed to confirm deleting their account.
     */
    public string $confirmation = '';

    #[Computed]
    public function user(): User
    {
        return Auth::user() ?? throw new AuthenticationException;
    }

    /**
     * The ways the user signs in, oldest first.
     *
     * @return Collection<int, SignInIdentity>
     */
    #[Computed]
    public function identities(): Collection
    {
        return $this->user->signInIdentities->sortBy('id')->values();
    }

    /**
     * What the user types to confirm deleting their account, and the label
     * asking for it: their GitHub login, or their email address when they
     * don't sign in with GitHub.
     *
     * @return array{value: string, label: string}
     */
    #[Computed]
    public function deletionConfirmation(): array
    {
        $login = $this->user->gitHubLogin();
        $email = $this->user->email;

        return match (true) {
            $login !== null => ['value' => $login, 'label' => __('Type your GitHub login, :login, to confirm', ['login' => $login])],
            filled($email) => ['value' => $email, 'label' => __('Type your email address, :email, to confirm', ['email' => $email])],
            default => ['value' => $this->user->name, 'label' => __('Type your name, :name, to confirm', ['name' => $this->user->name])],
        };
    }

    public function deleteAccount(DeleteAccount $deleteAccount): void
    {
        ['value' => $value, 'label' => $label] = $this->deletionConfirmation;

        $this->validate(
            ['confirmation' => ['required', Rule::in([$value])]],
            [
                'confirmation.required' => $label.'.',
                'confirmation.in' => __('That doesn\'t match. Type :value exactly to confirm.', ['value' => $value]),
            ],
        );

        $deleteAccount->handle($this->user);

        session()->flash('toast', ['variant' => 'success', 'text' => __('Your account and everything in it were deleted.')]);

        $this->redirectRoute('home');
    }
};
