<?php

namespace App\Enums;

enum RoleSlug: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Farmer = 'farmer';
    case Buyer = 'buyer';
    case BusinessBuyer = 'business_buyer';
    case DeliveryPartner = 'delivery_partner';
    case SupportAgent = 'support_agent';

    /**
     * Roles a person may choose when signing up in the mobile app.
     * Staff roles are only ever granted by an administrator.
     *
     * @return list<self>
     */
    public static function selfRegistrable(): array
    {
        return [self::Buyer, self::Farmer, self::BusinessBuyer];
    }
}
