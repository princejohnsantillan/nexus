<x-legal-page :title="__('Privacy Policy')" updated="2026-10-03">
    <p>{{ __('This policy explains what Nexus keeps about you, why, who else handles it and what Nexus never keeps. Nexus is run from the Philippines by its owner, who decides how your personal information is used (its personal information controller under the Data Privacy Act of 2012) and is "we" and "us" here.') }}</p>

    <h2>{{ __('What we keep') }}</h2>
    <ul>
        <li><strong>{{ __('Your account.') }}</strong> {{ __('If you sign in with GitHub: your GitHub account\'s id, username, name, email address and profile picture. If you sign in with an email code: your email address. Each code is stored only as a hash, works once and expires after 10 minutes.') }}</li>
        <li><strong>{{ __('Your Connections.') }}</strong> {{ __('Each server\'s address, the name and note you give it, the account it signed in as, and the tools and prompts it lists. Its credentials (OAuth tokens, API keys and headers) are encrypted with AES-256-GCM under a key of your own, which is itself encrypted with a master key kept apart from the database, so the database alone reveals nothing.') }}</li>
        <li><strong>{{ __('Your Stars.') }}</strong> {{ __('Their names, descriptions and settings, and which tools and prompts are switched on. Star tokens are stored only as a hash. For a Star that clients sign in to, the apps you approved.') }}</li>
        <li><strong>{{ __('Activity.') }}</strong> {{ __('For each tool call or prompt fetch through a Star: when it happened, the Star, the Connection, the tool or prompt\'s name, how it ended, how long it took and which client sent it (its token\'s or app\'s name). Each entry is kept for 30 days and then deleted.') }}</li>
        <li><strong>{{ __('Payments.') }}</strong> {{ __('Your plan, each payment\'s period, amount, status and PayMongo reference, and when your Pro ends. Your card and e-wallet details go to PayMongo, never to us.') }}</li>
        <li><strong>{{ __('Technical details.') }}</strong> {{ __('A session cookie that keeps you signed in, your browser\'s time zone so Activity shows your local time, and your IP address, which our rate limits and our hosting provider\'s logs see for a short time. Your choice of light or dark mode stays in your browser.') }}</li>
    </ul>

    <h2>{{ __('What we never keep') }}</h2>
    <ul>
        <li>{{ __('The arguments and results of your tool calls, and the prompts your clients fetch. They pass through Nexus to the server and back, and are never stored or logged.') }}</li>
        <li>{{ __('The text servers send back when something fails.') }}</li>
        <li>{{ __('Passwords: Nexus has none.') }}</li>
    </ul>
    <p>{{ __('We don\'t sell your information, show ads or use advertising or analytics trackers.') }}</p>

    <h2>{{ __('Why we use it') }}</h2>
    <ul>
        <li>{{ __('To sign you in and run Nexus for you: connecting your servers, serving your Stars and showing your Activity.') }}</li>
        <li>{{ __('To take payments and keep track of your plan.') }}</li>
        <li>{{ __('To email you sign-in codes, and reminders before and after your Pro ends.') }}</li>
        <li>{{ __('To keep Nexus secure and within its limits, and to fix problems.') }}</li>
        <li>{{ __('To answer you when you write to us, and to keep the records the law requires.') }}</li>
    </ul>
    <p>{{ __('We rely on our agreement with you (the Terms of Service), our legitimate interest in keeping Nexus safe and working, and our legal obligations.') }}</p>

    <h2>{{ __('Who else handles it') }}</h2>
    <p>{{ __('We share your information only with the services that run Nexus for us, and only what each needs:') }}</p>
    <ul>
        <li><strong>{{ __('PayMongo') }}</strong> {{ __('processes payments, and receives what you enter on its checkout.') }}</li>
        <li><strong>{{ __('GitHub') }}</strong> {{ __('signs you in when you choose GitHub.') }}</li>
        <li><strong>{{ __('Our hosting provider') }}</strong> {{ __('runs Nexus\'s servers, database and backups. They may be outside the Philippines; we choose providers that protect data at least as well as the Data Privacy Act asks.') }}</li>
        <li><strong>{{ __('Our email provider') }}</strong> {{ __('delivers sign-in codes and reminders.') }}</li>
        <li><strong>{{ __('The MCP servers you connect') }}</strong> {{ __('receive your credentials and your clients\' calls, because that is what you ask Nexus to do. Each follows its own service\'s privacy policy.') }}</li>
    </ul>
    <p>{{ __('We disclose information to the authorities only when the law requires it.') }}</p>

    <h2>{{ __('How long we keep it') }}</h2>
    <ul>
        <li>{{ __('Your account, Connections and Stars: until you delete them or your account.') }}</li>
        <li>{{ __('Activity: 30 days.') }}</li>
        <li>{{ __('Sign-in codes: until they\'re used or expire. Expired codes are removed daily.') }}</li>
        <li>{{ __('Payment records: as long as Philippine tax and accounting rules require, even after you delete your account.') }}</li>
        <li>{{ __('Backups: deleted data stays in the database\'s backups until they age out, after a limited time.') }}</li>
    </ul>

    <h2>{{ __('Deleting your account') }}</h2>
    <p>{{ __('Delete your account from Settings. It permanently removes your account and sign-in methods, your Stars, your Connections with their credentials and your encryption key, your tokens, the apps you approved and your activity, at once. Backups keep a copy until they age out, and payment records stay as long as the law requires.') }}</p>

    <h2>{{ __('Your rights') }}</h2>
    <p>{{ __('Under the Philippine Data Privacy Act of 2012 (Republic Act No. 10173), you have the right to be informed, to access your information, to object to its processing, to have it corrected, to have it erased or blocked, to take it with you, and to be compensated for damages. You can see and change most of your information in Nexus itself. For anything else, email') }} <x-contact-email />.</p>
    <p>{{ __('If you think we\'ve mishandled your information, you can also complain to the') }} <flux:link href="https://privacy.gov.ph" external>{{ __('National Privacy Commission') }}</flux:link>.</p>

    <h2>{{ __('Security') }}</h2>
    <p>{{ __('Credentials are encrypted under a key of your own, tokens and sign-in codes are kept only as hashes, and every connection to Nexus uses HTTPS. No system is perfectly secure: if a breach puts your information at risk, we\'ll tell you and the National Privacy Commission as the law requires.') }}</p>

    <h2>{{ __('Changes to this policy') }}</h2>
    <p>{{ __('The date at the top says when this policy last changed. Before a change that matters takes effect, we\'ll email you.') }}</p>

    <h2>{{ __('Contact') }}</h2>
    <p>{{ __('Questions or requests about your information? Email') }} <x-contact-email />.</p>
</x-legal-page>
