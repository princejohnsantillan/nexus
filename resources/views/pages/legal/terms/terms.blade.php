<x-legal-page :title="__('Terms of Service')" updated="2026-10-03">
    <p>{{ __('These terms are the agreement between you and Nexus, the remote MCP gateway on this website. Nexus is run from the Philippines by its owner, an individual, who is "we" and "us" here. By signing in, you agree to these terms. The') }} <flux:link :href="route('legal.privacy')">{{ __('Privacy Policy') }}</flux:link> {{ __('and the') }} <flux:link :href="route('legal.refunds')">{{ __('Refund Policy') }}</flux:link> {{ __('are part of them.') }}</p>

    <h2>{{ __('The service') }}</h2>
    <p>{{ __('Nexus lets you connect your MCP servers once and use them in every AI client. You connect each server as a Connection, bundle Connections into Stars, and choose which tools and prompts each Star offers. When one of your clients calls a tool, Nexus passes the call to the server, signed in as you, and passes the answer back.') }}</p>
    <p>{{ __('Nexus\'s code is open source and public on GitHub. These terms cover the service we run on this website, not copies of the code that other people run.') }}</p>

    <h2>{{ __('Accounts and sign-in') }}</h2>
    <ul>
        <li>{{ __('You sign in with your GitHub account or with a one-time code we email to you. Nexus has no passwords. Signing in for the first time creates your account.') }}</li>
        <li>{{ __('You must be at least 18 years old, or have your parent\'s or guardian\'s permission, to use Nexus.') }}</li>
        <li>{{ __('Keep your GitHub account, your email inbox, your Star tokens and your signed URLs safe: anyone who has one of them can use what it opens. You\'re responsible for what happens through your account. Revoke a token or rotate a URL as soon as you think someone else has it.') }}</li>
    </ul>

    <h2>{{ __('Your Connections') }}</h2>
    <p>{{ __('Connect only servers and accounts you\'re allowed to use, and follow those services\' own terms. Nexus acts for you: it uses the credentials you give it to call the tools your Stars allow, when your clients ask. What a tool does on the other service, and what your AI clients choose to call, is up to you and those services, so switch on only the tools you\'re comfortable letting your clients use.') }}</p>

    <h2>{{ __('Acceptable use') }}</h2>
    <p>{{ __('Don\'t use Nexus to:') }}</p>
    <ul>
        <li>{{ __('break the law or anyone\'s rights, including their intellectual property and privacy;') }}</li>
        <li>{{ __('reach servers or accounts you aren\'t allowed to use, or probe, scan or attack Nexus, its hosting or anyone else\'s systems;') }}</li>
        <li>{{ __('get around Nexus\'s limits or security, for example by opening several accounts to stay on Free or by sending requests to private networks;') }}</li>
        <li>{{ __('send malware, spam or anything else meant to do harm;') }}</li>
        <li>{{ __('overload Nexus, or resell access to it as a service of your own.') }}</li>
    </ul>

    <h2>{{ __('Plans and payments') }}</h2>
    <ul>
        <li><strong>{{ __('Free') }}</strong> {{ __('costs nothing. It limits how many Stars and Connections you can have and how many tool calls you can make each week. Nexus shows you the current limits.') }}</li>
        <li><strong>{{ __('Pro') }}</strong> {{ __('costs ₱499 for a month or ₱4,999 for a year, in Philippine pesos, and lifts those limits.') }}</li>
        <li>{{ __('Pro is prepaid. You pay on PayMongo\'s secure checkout, with a card or an e-wallet it offers, and PayMongo emails you a receipt. Each payment adds a month or a year of Pro, counted from the day you pay or from the end of the Pro you already have, whichever is later, so paying early never loses days.') }}</li>
        <li>{{ __('Pro is never renewed automatically. We never charge you unless you pay on the checkout yourself, so there is nothing to cancel. We email you before your Pro ends.') }}</li>
        <li>{{ __('When Pro ends, your account is back on Free. Nothing is deleted or switched off: Stars and Connections over Free\'s limits keep working, but you can\'t add more until you\'re under the limits, and Free\'s weekly tool calls apply.') }}</li>
        <li>{{ __('If we change Pro\'s price, the new price applies only to payments made after the change, never to Pro you\'ve already paid for.') }}</li>
        <li>{{ __('Refunds follow the') }} <flux:link :href="route('legal.refunds')">{{ __('Refund Policy') }}</flux:link>.</li>
    </ul>

    <h2>{{ __('Availability and changes') }}</h2>
    <p>{{ __('We work to keep Nexus running, but we provide it as it is, without a promise that it will always be available or free of errors. The servers you connect are run by others, and Nexus can\'t keep them available either. We may change, add or remove features, and we\'ll tell you ahead of any change that takes away something you\'ve paid for.') }}</p>

    <h2>{{ __('Your content') }}</h2>
    <p>{{ __('What you put in Nexus, such as your Connections, credentials, Stars and settings, stays yours. We use it only to run Nexus for you, as the Privacy Policy describes. We never store the arguments or results of your tool calls.') }}</p>

    <h2>{{ __('Ending your account') }}</h2>
    <ul>
        <li>{{ __('You can delete your account at any time from Settings. It removes your Stars, Connections, tokens and activity at once. Deleting it doesn\'t refund Pro time you have left, except as the Refund Policy says.') }}</li>
        <li>{{ __('We may suspend or close an account that breaks these terms or puts Nexus or other people at risk. We\'ll tell you why when we can.') }}</li>
        <li>{{ __('If we ever shut Nexus down, we\'ll tell you at least 30 days ahead and refund the Pro time you\'ve paid for but can\'t use.') }}</li>
    </ul>

    <h2>{{ __('Liability') }}</h2>
    <p>{{ __('As far as Philippine law allows, we aren\'t liable for indirect or consequential losses, such as lost profits or lost data, or for what the servers you connect or your AI clients do. Our total liability to you is limited to what you paid us in the 12 months before the claim. Nothing in these terms limits a liability that the law doesn\'t allow to be limited, such as for fraud or gross negligence.') }}</p>

    <h2>{{ __('Changes to these terms') }}</h2>
    <p>{{ __('We may update these terms. The date at the top says when they last changed. Before a change that matters takes effect, we\'ll email you. If you keep using Nexus after that, you accept the new terms; if you don\'t want to, you can delete your account.') }}</p>

    <h2>{{ __('Governing law') }}</h2>
    <p>{{ __('These terms are governed by the laws of the Republic of the Philippines. If we can\'t settle a dispute by talking it through, the courts of the Philippines will decide it.') }}</p>

    <h2>{{ __('Contact') }}</h2>
    <p>{{ __('Questions about these terms? Email') }} <x-contact-email />.</p>
</x-legal-page>
