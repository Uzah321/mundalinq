<?php

namespace App\Enums;

enum FarmVerificationStatus: string
{
    case Unverified = 'unverified';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
