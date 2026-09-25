<?php

namespace App\Identity;

enum AccountStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    /** Deletion requested. The erasure job (ADR 0001) removes the data later. */
    case Deleted = 'deleted';
}
