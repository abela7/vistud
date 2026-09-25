<?php

namespace App\Brain\Journal;

enum Kind: string
{
    case Exposure = 'exposure';
    case Attempt = 'attempt';
    case Question = 'question';
    case SelfReport = 'self_report';
    case Claim = 'claim';
    case Record = 'record';
    case Amendment = 'amendment';

    public function isObservation(): bool
    {
        return in_array($this, [self::Exposure, self::Attempt, self::Question, self::SelfReport], true);
    }
}
