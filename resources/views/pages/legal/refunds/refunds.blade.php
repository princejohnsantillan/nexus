<x-legal-page :title="__('Refund Policy')" updated="2026-10-03">
    <p>{{ __('Pro is prepaid for a month or a year and never renews on its own, so you\'re never charged by surprise. If you change your mind after paying, this is how refunds work.') }}</p>

    <h2>{{ __('Within 7 days of a payment') }}</h2>
    <p>{{ __('If you change your mind, you can have a payment refunded within 7 days of making it. Email') }} <x-contact-email /> {{ __('from the address on your account, or with your GitHub username, and say which payment you\'d like refunded.') }}</p>
    <p>{{ __('We refund the whole payment, and take the month or year it bought off your Pro. If no Pro time is left after that, your account goes back to Free, and everything in it keeps working.') }}</p>

    <h2>{{ __('After 7 days') }}</h2>
    <p>{{ __('After 7 days, a payment isn\'t refunded, including for the Pro time left. Your Pro simply runs until its end date and isn\'t renewed, so there\'s nothing to cancel.') }}</p>

    <h2>{{ __('Mistakes') }}</h2>
    <p>{{ __('If you were charged twice, or paid and didn\'t get Pro, email us whenever you notice. We\'ll put it right or refund the payment.') }}</p>

    <h2>{{ __('How you get your money back') }}</h2>
    <p>{{ __('Refunds go back through PayMongo to the card or e-wallet you paid with. How long one takes to reach you depends on your bank or e-wallet.') }}</p>

    <h2>{{ __('Deleting your account') }}</h2>
    <p>{{ __('Deleting your account doesn\'t refund the Pro time you have left. If you want a payment refunded within its 7 days, ask before you delete your account.') }}</p>

    <h2>{{ __('Contact') }}</h2>
    <p>{{ __('Questions about a payment or a refund? Email') }} <x-contact-email />.</p>
</x-legal-page>
