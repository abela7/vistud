<?php

namespace App\Platform\Access;

/** Account roles (ADR 0003 §10.3). A user may hold both. */
enum Role: string
{
    case Student = 'student';
    case Admin = 'admin';
}
