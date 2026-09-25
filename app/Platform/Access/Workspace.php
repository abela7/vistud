<?php

namespace App\Platform\Access;

/** The two workspaces in one application (ADR 0003 §10). The active one is kept in the session. */
enum Workspace: string
{
    case Student = 'student';
    case Admin = 'admin';
}
