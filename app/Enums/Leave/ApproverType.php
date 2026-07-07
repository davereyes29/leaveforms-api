<?php

namespace App\Enums\Leave;

enum ApproverType: string
{
    case Leader = 'leader';
    case Am = 'am';
    case Dm = 'dm';
    case Sm = 'sm';
    case Admin = 'admin';
    case President = 'president';
}
