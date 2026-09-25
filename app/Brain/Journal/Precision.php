<?php

namespace App\Brain\Journal;

enum Precision: string
{
    case Exact = 'exact';
    case Minute = 'minute';
    case Day = 'day';
    case Week = 'week';
}
