<?php

namespace App\Enums;

enum FarmMemberRole: string
{
    /** Full control, including verification and members. */
    case Owner = 'owner';

    /** Day-to-day running: profile, locations, and later products and orders. */
    case Manager = 'manager';
}
