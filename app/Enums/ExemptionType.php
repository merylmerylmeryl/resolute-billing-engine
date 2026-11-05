<?php

namespace App\Enums;

enum ExemptionType: string
{
    case None = 'none';
    case Flat = 'flat';
    case Percent = 'percent';
}
