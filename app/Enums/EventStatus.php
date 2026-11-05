<?php

namespace App\Enums;

enum EventStatus: string
{
    case Pending = 'pending';
    case Final = 'final';
}
