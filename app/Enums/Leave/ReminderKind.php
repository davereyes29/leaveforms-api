<?php

namespace App\Enums\Leave;

enum ReminderKind: string
{
    case Bulk = 'bulk';
    case Single = 'single';
}
