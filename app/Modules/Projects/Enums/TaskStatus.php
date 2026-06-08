<?php

namespace App\Modules\Projects\Enums;

enum TaskStatus: string
{
    case ToDo = 'to_do';
    case InProgress = 'in_progress';
    case Done = 'done';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
