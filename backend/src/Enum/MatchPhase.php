<?php

namespace App\Enum;

enum MatchPhase: string
{
    case Group = 'group';
    case Tiebreaker = 'tiebreaker';
    case Winner = 'winner';
    case Loser = 'loser';
    case Final = 'final';
}
