<?php

declare(strict_types=1);

namespace App\Support\Database;

use BackedEnum;
use Illuminate\Support\Facades\DB;

/**
 * Adds MySQL CHECK constraints that restrict a column to the values of a
 * backed enum. The PHP enum stays the single source of truth (design §4.2).
 */
final class CheckConstraint
{
    /**
     * @param  class-string<BackedEnum>  $enum
     */
    public static function forEnum(string $table, string $column, string $enum): void
    {
        $values = array_map(
            static fn (BackedEnum $case): string => is_int($case->value)
                ? (string) $case->value
                : DB::getPdo()->quote($case->value),
            $enum::cases(),
        );

        DB::statement(sprintf(
            'ALTER TABLE `%s` ADD CONSTRAINT `chk_%s_%s` CHECK (`%s` IN (%s))',
            $table,
            $table,
            $column,
            $column,
            implode(', ', $values),
        ));
    }
}
