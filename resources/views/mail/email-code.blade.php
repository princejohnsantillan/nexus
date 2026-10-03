<x-mail::message>
@if ($purpose === App\Enums\EmailCodePurpose::SignIn)
# {{ __('Your sign-in code') }}

{{ __('Enter this code on the Nexus sign-in page to sign in with this address. If nobody signs in with it yet, a new account is created.') }}
@else
# {{ __('Confirm your email address') }}

{{ __('Enter this code in Nexus Settings to add this address as a way to sign in to your account.') }}
@endif

<x-mail::panel>
<span style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 28px; font-weight: 700; letter-spacing: 6px;">{{ $code }}</span>
</x-mail::panel>

{{ __('It expires in :minutes minutes and works once.', ['minutes' => $minutes]) }}

{{ __('If you didn\'t ask for it, you can ignore this email: nothing happens without the code.') }}
</x-mail::message>
