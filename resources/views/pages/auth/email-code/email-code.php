<?php

declare(strict_types=1);

use App\Actions\SignInWithEmail;
use App\Auth\EmailCodes;
use App\Auth\PendingEmailSignIn;
use App\Enums\EmailCodePurpose;
use App\Exceptions\EmailCodeNotSent;
use App\Exceptions\EmailCodeRejected;
use App\Exceptions\TooManyEmailCodes;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Layout('layouts::public'), Title('Check your inbox')] class extends Component
{
    /**
     * Where the code was sent, from the sign-in page.
     */
    #[Locked]
    public string $email = '';

    public string $code = '';

    public function mount(): void
    {
        abort_unless(EmailCodes::canBeSent(), 404);

        $email = PendingEmailSignIn::address();

        if ($email === null) {
            $this->redirectRoute('auth.sign-in');

            return;
        }

        $this->email = $email;
    }

    /**
     * How long until another code can be sent, for the Resend countdown.
     */
    #[Computed]
    public function secondsUntilResend(): int
    {
        return resolve(EmailCodes::class)->secondsUntilNextSend($this->email);
    }

    public function signIn(EmailCodes $codes, SignInWithEmail $signInWithEmail): void
    {
        $this->validate(
            ['code' => ['required', 'digits:'.EmailCodes::LENGTH]],
            [
                'code.required' => __('Enter the 6-digit code from the email.'),
                'code.digits' => __('Enter all 6 digits of the code.'),
            ],
        );

        try {
            $codes->verify($this->email, EmailCodePurpose::SignIn, null, $this->code);
        } catch (EmailCodeRejected $rejected) {
            $this->addError('code', $rejected->getMessage());

            return;
        }

        Auth::login($signInWithEmail->handle($this->email), remember: true);

        session()->regenerate();

        PendingEmailSignIn::forget();

        $this->redirectIntended(route('stars.index'));
    }

    public function resend(EmailCodes $codes): void
    {
        try {
            $codes->send($this->email, EmailCodePurpose::SignIn);
        } catch (TooManyEmailCodes $tooMany) {
            Flux::toast(variant: 'warning', text: $tooMany->getMessage());

            return;
        } catch (EmailCodeNotSent $notSent) {
            Flux::toast(variant: 'danger', text: $notSent->getMessage());

            return;
        }

        $this->reset('code');
        $this->resetErrorBag();
        unset($this->secondsUntilResend);

        Flux::toast(variant: 'success', text: __('We sent a new code to :email.', ['email' => $this->email]));
    }
};
