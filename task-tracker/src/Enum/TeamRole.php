<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Role of a user inside one team. Stored as VARCHAR (native MySQL ENUMs make
 * migrations painful); the enum lives in code and the app validates values.
 */
enum TeamRole: string
{
    case Member = 'member';
    case Admin = 'admin';
}
