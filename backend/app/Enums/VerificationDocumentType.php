<?php

namespace App\Enums;

enum VerificationDocumentType: string
{
    case Identity = 'identity';
    case BusinessRegistration = 'business_registration';
    case LandProof = 'land_proof';
    case Other = 'other';
}
