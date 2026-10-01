<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ToolCallStatus: string implements HasColor, HasLabel
{
    case Ok = 'ok';
    case ToolError = 'tool_error';
    case AuthRequired = 'auth_required';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::ToolError => 'Tool error',
            self::AuthRequired => 'Needs sign-in',
            self::Failed => 'Failed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::ToolError => 'warning',
            self::AuthRequired => 'warning',
            self::Failed => 'danger',
        };
    }
}
