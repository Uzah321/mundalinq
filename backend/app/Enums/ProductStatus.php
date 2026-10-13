<?php

namespace App\Enums;

enum ProductStatus: string
{
    /** Being prepared by the farmer; never visible to buyers. */
    case Draft = 'draft';

    /** Published by the farmer and waiting for an admin. */
    case PendingReview = 'pending_review';

    /** Live: buyers can find and order it (while unexpired and in stock). */
    case Active = 'active';

    /** Temporarily hidden by the farmer. */
    case Paused = 'paused';

    /** Not approved, or taken down by an admin; the farmer can fix it and publish again. */
    case Rejected = 'rejected';

    public function canBePublished(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }

    /**
     * Published at least as far as review: it must keep a photo and a selling option.
     */
    public function isSubmitted(): bool
    {
        return in_array($this, [self::PendingReview, self::Active, self::Paused], true);
    }
}
