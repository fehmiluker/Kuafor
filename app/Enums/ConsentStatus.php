<?php

namespace App\Enums;

enum ConsentStatus: string
{
    use HasValues;

    case Granted = 'granted';
    case Withdrawn = 'withdrawn';
}
