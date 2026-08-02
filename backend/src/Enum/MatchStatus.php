<?php

namespace App\Enum;

enum MatchStatus: string
{
    case Pending = 'pending';
    case Live = 'live';
    case Done = 'done';
}
