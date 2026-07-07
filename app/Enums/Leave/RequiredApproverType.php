<?php

namespace App\Enums\Leave;

enum RequiredApproverType: string
{
    case President = 'president';
    case Admin = 'admin';
}
