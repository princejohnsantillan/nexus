<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ConnectionStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Active = 'active';
    case NeedsAuth = 'needs_auth';
    case Error = 'error';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Not connected',
            self::Active => 'Active',
            self::NeedsAuth => 'Needs sign-in',
            self::Error => 'Error',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Active => 'success',
            self::NeedsAuth => 'warning',
            self::Error => 'danger',
        };
    }
}
