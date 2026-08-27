<?php

namespace App\Enums;

enum AdminRole: string
{
    case SuperAdmin = 'super-admin';
    case CinemaAdmin = 'cinema-admin';
    case Gate = 'gate';
}
