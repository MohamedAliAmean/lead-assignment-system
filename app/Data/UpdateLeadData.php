<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\LeadPriority;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;

/**
 * Full set of editable lead fields. Partial updates (API PATCH) are merged
 * with the current lead first, see fromPartial().
 */
final readonly class UpdateLeadData
{
    public string $phone;

    public function __construct(
        public string $name,
        string $phone,
        public LeadSource $source,
        public LeadPriority $priority,
        public ?CarbonImmutable $nextFollowUpAt,
        public ?LeadStatus $status = null,
    ) {
        $this->phone = PhoneNumber::normalize($phone);
    }

    /**
     * @param  array<string, mixed>  $data  validated input; missing keys keep current values
     */
    public static function fromPartial(Lead $lead, array $data): self
    {
        return new self(
            name: array_key_exists('name', $data) ? (string) $data['name'] : $lead->name,
            phone: array_key_exists('phone', $data) ? (string) $data['phone'] : $lead->phone,
            source: array_key_exists('source', $data) ? self::enum(LeadSource::class, $data['source']) : $lead->source,
            priority: array_key_exists('priority', $data) ? self::enum(LeadPriority::class, $data['priority']) : $lead->priority,
            nextFollowUpAt: array_key_exists('next_follow_up_at', $data)
                ? ($data['next_follow_up_at'] === null || $data['next_follow_up_at'] === '' ? null : CarbonImmutable::parse($data['next_follow_up_at']))
                : $lead->next_follow_up_at,
            status: array_key_exists('status', $data) && $data['status'] !== null
                ? self::enum(LeadStatus::class, $data['status'])
                : null,
        );
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    private static function enum(string $enum, mixed $value): \BackedEnum
    {
        if ($value instanceof $enum) {
            return $value;
        }

        return $enum::from(is_numeric($value) && $enum === LeadPriority::class ? (int) $value : $value);
    }
}
