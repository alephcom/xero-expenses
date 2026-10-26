<?php

namespace App\Enums;

/**
 * How a user came to belong to an organisation. Only {@see self::XeroSso} memberships are managed
 * automatically: they are re-checked against Xero on each sign-in and removed when Xero no longer grants access.
 */
enum OrganisationMembershipSource: string
{
    case Manual = 'manual';
    case Invitation = 'invitation';
    case XeroConnect = 'xero_connect';
    case XeroSso = 'xero_sso';
}
