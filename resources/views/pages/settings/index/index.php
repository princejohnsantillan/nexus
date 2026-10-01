<?php

declare(strict_types=1);

use App\Actions\DeleteAccount;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Title('Settings')] class extends Component
{
    /**
     * What the user typed to confirm deleting their account: their GitHub login.
     */
    public string $confirmation = '';

    #[Computed]
    public function user(): User
    {
        return Auth::user() ?? throw new AuthenticationException;
    }

    public function deleteAccount(DeleteAccount $deleteAccount): void
    {
        $login = $this->user->github_login;

        $this->validate(
            ['confirmation' => ['required', Rule::in([$login])]],
            [
                'confirmation.required' => __('Type your GitHub login, :login, to confirm.', ['login' => $login]),
                'confirmation.in' => __('That doesn\'t match. Type :login exactly to confirm.', ['login' => $login]),
            ],
        );

        $deleteAccount->handle($this->user);

        session()->flash('toast', ['variant' => 'success', 'text' => __('Your account and everything in it were deleted.')]);

        $this->redirectRoute('home');
    }
};
