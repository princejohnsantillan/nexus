<?php

declare(strict_types=1);

use App\Actions\AddSignInIdentity;
use App\Actions\DeleteAccount;
use App\Actions\RemoveSignInIdentity;
use App\Auth\EmailCodes;
use App\Auth\GoogleSignInProvider;
use App\Enums\EmailCodePurpose;
use App\Enums\IdentityProvider;
use App\Exceptions\EmailCodeRejected;
use App\Exceptions\IdentityBelongsToAnotherUser;
use App\Exceptions\TooManyEmailCodes;
use App\Models\SignInIdentity;
use App\Models\User;
use Flux\Flux;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Title('Settings')] class extends Component
{
    /**
     * What the user typed to confirm deleting their account.
     */
    public string $confirmation = '';

    /**
     * The email address the user is adding as a way to sign in.
     */
    public string $newEmail = '';

    /**
     * Where the code to add an email address went, once it was sent.
     */
    #[Locked]
    public ?string $newEmailSentTo = null;

    public string $newEmailCode = '';

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

    #[Computed]
    public function googleSignInIsEnabled(): bool
    {
        return GoogleSignInProvider::isConfigured();
    }

    /**
     * Remove one of the user's sign-in identities, as long as another one remains.
     */
    public function removeIdentity(int $identityId, RemoveSignInIdentity $removeSignInIdentity): void
    {
        $identity = $this->user->signInIdentities()->findOrFail($identityId);

        $removeSignInIdentity->handle($identity);

        $this->user->unsetRelation('signInIdentities');
        unset($this->identities, $this->deletionConfirmation);

        $this->dispatch('modal-close', name: "remove-sign-in-identity-{$identityId}", scope: $this->getId());
        Flux::toast(variant: 'success', text: __('Removed :provider. You can no longer sign in with :name.', [
            'provider' => $identity->provider->label(),
            'name' => $identity->displayName(),
        ]));
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

    #[Computed]
    public function addingEmailIsEnabled(): bool
    {
        return EmailCodes::canBeSent();
    }

    /**
     * How long until another code can be sent to the address being added,
     * for the Resend countdown.
     */
    #[Computed]
    public function secondsUntilNewEmailResend(): int
    {
        return $this->newEmailSentTo === null ? 0 : resolve(EmailCodes::class)->secondsUntilNextSend($this->newEmailSentTo);
    }

    /**
     * Email a code to the address the user is adding, to prove it's theirs.
     * Whether someone else signs in with the address is only said once the
     * code proves it, so this reveals nothing about other accounts.
     */
    public function sendNewEmailCode(EmailCodes $codes): void
    {
        abort_unless($this->addingEmailIsEnabled, 404);

        $this->newEmail = trim($this->newEmail);

        $this->validate(
            ['newEmail' => ['required', 'string', 'email', 'max:255']],
            [
                'newEmail.required' => __('Enter the email address to add.'),
                'newEmail.email' => __('Enter a valid email address.'),
                'newEmail.max' => __('Enter an email address of 255 characters or fewer.'),
            ],
        );

        $email = EmailCodes::address($this->newEmail);

        if ($this->identities->contains(fn (SignInIdentity $identity): bool => $identity->provider === IdentityProvider::Email && $identity->provider_user_id === $email)) {
            $this->addError('newEmail', __('You already sign in with this address.'));

            return;
        }

        try {
            $codes->send($email, EmailCodePurpose::AddToAccount, $this->user);
        } catch (TooManyEmailCodes $tooMany) {
            $this->addError('newEmail', $tooMany->getMessage());

            return;
        }

        $this->newEmailSentTo = $email;
        $this->reset('newEmailCode');
        unset($this->secondsUntilNewEmailResend);
    }

    public function resendNewEmailCode(EmailCodes $codes): void
    {
        if ($this->newEmailSentTo === null) {
            return;
        }

        try {
            $codes->send($this->newEmailSentTo, EmailCodePurpose::AddToAccount, $this->user);
        } catch (TooManyEmailCodes $tooMany) {
            Flux::toast(variant: 'warning', text: $tooMany->getMessage());

            return;
        }

        $this->reset('newEmailCode');
        $this->resetErrorBag();
        unset($this->secondsUntilNewEmailResend);

        Flux::toast(variant: 'success', text: __('We sent a new code to :email.', ['email' => $this->newEmailSentTo]));
    }

    /**
     * Add the address once the code sent to it is entered. An address
     * someone else signs in with is refused.
     */
    public function addNewEmail(EmailCodes $codes, AddSignInIdentity $addSignInIdentity): void
    {
        $email = $this->newEmailSentTo;

        if ($email === null) {
            return;
        }

        $this->validate(
            ['newEmailCode' => ['required', 'digits:'.EmailCodes::LENGTH]],
            [
                'newEmailCode.required' => __('Enter the 6-digit code from the email.'),
                'newEmailCode.digits' => __('Enter all 6 digits of the code.'),
            ],
        );

        try {
            $codes->verify($email, EmailCodePurpose::AddToAccount, $this->user, $this->newEmailCode);
        } catch (EmailCodeRejected $rejected) {
            $this->addError('newEmailCode', $rejected->getMessage());

            return;
        }

        try {
            $identity = $addSignInIdentity->handle($this->user, IdentityProvider::Email, $email, $email);
        } catch (IdentityBelongsToAnotherUser) {
            $this->reset('newEmailSentTo', 'newEmailCode');
            $this->addError('newEmail', __('This address already signs in to another Nexus account, so it can\'t be added to this one.'));

            return;
        }

        $this->resetNewEmail();
        $this->user->load('signInIdentities');
        unset($this->identities);

        $this->dispatch('modal-close', name: 'add-email', scope: $this->getId());
        Flux::toast(variant: 'success', text: $identity->wasRecentlyCreated
            ? __('Added. You can now sign in with :email.', ['email' => $email])
            : __(':email is already one of your sign-in methods.', ['email' => $email]));
    }

    /**
     * Go back to the address, to correct it or pick another.
     */
    public function changeNewEmail(): void
    {
        $this->reset('newEmailSentTo', 'newEmailCode');
        $this->resetErrorBag(['newEmail', 'newEmailCode']);
    }

    /**
     * Start adding an email address over, as when the modal closes.
     */
    public function resetNewEmail(): void
    {
        $this->reset('newEmail', 'newEmailSentTo', 'newEmailCode');
        $this->resetErrorBag(['newEmail', 'newEmailCode']);
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
