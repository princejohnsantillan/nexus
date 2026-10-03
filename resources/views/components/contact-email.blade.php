@php
    $address = config()->string('nexus.contact_email');
@endphp

<flux:link href="mailto:{{ $address }}" {{ $attributes }}>{{ $address }}</flux:link>
