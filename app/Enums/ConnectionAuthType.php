<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ConnectionAuthType: string implements HasLabel
{
    case None = 'none';
    case Header = 'header';
    case OAuth = 'oauth';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'No authentication',
            self::Header => 'Static header (API key or token)',
            self::OAuth => 'OAuth',
        };
    }
}
