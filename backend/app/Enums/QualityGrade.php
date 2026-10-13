<?php

namespace App\Enums;

enum QualityGrade: string
{
    /** Top quality: uniform size and appearance. */
    case A = 'A';

    /** Good quality with minor blemishes or size variation. */
    case B = 'B';

    /** Economy or processing grade. */
    case C = 'C';
}
