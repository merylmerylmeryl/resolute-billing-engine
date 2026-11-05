<?php

namespace App\Enums;

enum InvoiceType: string
{
    case Invoice = 'invoice';
    case Adjustment = 'adjustment';
}
