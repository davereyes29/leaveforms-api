<?php

namespace App\Enums\Leave;

enum VacationLeaveQuarterLevel: string
{
    case Ok = 'ok';
    case AtCap = 'at_cap';
    case Exceeded = 'exceeded';
}
