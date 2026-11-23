<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum UserRole: string
{
    use HasValues;

    case Admin = 'admin';
    case SalesManager = 'sales_manager';
    case SalesAgent = 'sales_agent';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::SalesManager => 'Sales Manager',
            self::SalesAgent => 'Sales Agent',
        };
    }
}
