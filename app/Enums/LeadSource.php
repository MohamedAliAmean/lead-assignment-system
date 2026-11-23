<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum LeadSource: string
{
    use HasValues;

    case Website = 'website';
    case Facebook = 'facebook';
    case GoogleAds = 'google_ads';
    case Referral = 'referral';
    case WalkIn = 'walk_in';
    case Api = 'api';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Website => 'Website',
            self::Facebook => 'Facebook',
            self::GoogleAds => 'Google Ads',
            self::Referral => 'Referral',
            self::WalkIn => 'Walk-in',
            self::Api => 'API',
            self::Other => 'Other',
        };
    }
}
