<?php

namespace App\Enums;

enum OtpPurpose: string
{
    case VerifyPhone = 'verify_phone';
    case PasswordReset = 'password_reset';

    /** Second step of a staff sign-in. */
    case StaffSignIn = 'staff_sign_in';
}
