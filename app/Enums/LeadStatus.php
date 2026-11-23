<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum LeadStatus: string
{
    use HasValues;

    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Unqualified = 'unqualified';
    case Won = 'won';
    case Lost = 'lost';

    /**
     * Open leads still need work. Closed leads are never "overdue".
     */
    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::New, self::Contacted, self::Qualified];
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::open());
    }

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Qualified => 'Qualified',
            self::Unqualified => 'Unqualified',
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }

    /**
     * Semantic color name, used by the admin UI badges.
     */
    public function color(): string
    {
        return match ($this) {
            self::New => 'info',
            self::Contacted => 'warning',
            self::Qualified => 'primary',
            self::Unqualified => 'gray',
            self::Won => 'success',
            self::Lost => 'danger',
        };
    }
}
