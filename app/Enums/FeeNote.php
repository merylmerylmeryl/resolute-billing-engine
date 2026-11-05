<?php

namespace App\Enums;

enum FeeNote: string
{
    case Waived = 'waived';
    case Capped = 'capped';
    case NoSavings = 'no_savings';
}
