<?php

namespace App\Enums;

enum IdDocumentType: string
{
    case NationalId = 'national_id';
    case Passport = 'passport';
}
