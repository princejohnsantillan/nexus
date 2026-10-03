<?php

declare(strict_types=1);

use App\Auth\EmailCodes;
use App\Auth\GoogleSignInProvider;
use App\Auth\PendingEmailSignIn;
use App\Enums\DevAccount;
use App\Enums\EmailCodePurpose;
use App\Exceptions\TooManyEmailCodes;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Layout('layouts::public'), Title('Sign in')] class extends Component
{
    /**
     * The address to email a sign-in code to.
     */
    public string $email = '';

    #[Computed]
    public function emailSignInIsEnabled(): bool
    {
        return EmailCodes::canBeSent();
    }

    #[Computed]
    public function googleSignInIsEnabled(): bool
    {
        return GoogleSignInProvider::isConfigured();
    }

    #[Computed]
    public function devSignInIsEnabled(): bool
    {
        return DevAccount::signInIsEnabled();
    }

    /**
     * Email a sign-in code to the address and go on to enter it. It's the
     * same whether or not anyone signs in with the address yet.
     */
    public function sendCode(EmailCodes $codes): void
    {
        abort_unless($this->emailSignInIsEnabled, 404);

        $this->email = trim($this->email);

        $this->validate(
            ['email' => ['required', 'string', 'email', 'max:255']],
            [
                'email.required' => __('Enter your email address.'),
                'email.email' => __('Enter a valid email address.'),
                'email.max' => __('Enter an email address of 255 characters or fewer.'),
            ],
        );

        try {
            $codes->send($this->email, EmailCodePurpose::SignIn);
        } catch (TooManyEmailCodes $tooMany) {
            $this->addError('email', $tooMany->getMessage());

            return;
        }

        PendingEmailSignIn::remember($this->email);

        $this->redirectRoute('auth.email-code');
    }
};
