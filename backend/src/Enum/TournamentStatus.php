<?php

namespace App\Enum;

enum TournamentStatus: string
{
    case Draft = 'draft';
    case Registration = 'registration';
    case Groups = 'groups';
    case Bracket = 'bracket';
    case Finished = 'finished';
}
