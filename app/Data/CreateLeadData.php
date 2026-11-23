<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\LeadPriority;
use App\Enums\LeadSource;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;

final readonly class CreateLeadData
{
    public string $phone;

    public function __construct(
        public string $name,
        string $phone,
        public LeadSource $source,
        public LeadPriority $priority = LeadPriority::Medium,
        public ?CarbonImmutable $nextFollowUpAt = null,
        public ?string $externalRef = null,
    ) {
        $this->phone = PhoneNumber::normalize($phone);
    }

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            phone: (string) $data['phone'],
            source: $data['source'] instanceof LeadSource ? $data['source'] : LeadSource::from((string) $data['source']),
            priority: self::priority($data['priority'] ?? null),
            nextFollowUpAt: self::date($data['next_follow_up_at'] ?? null),
            externalRef: isset($data['external_ref']) ? (string) $data['external_ref'] : null,
        );
    }

    private static function priority(mixed $value): LeadPriority
    {
        return match (true) {
            $value instanceof LeadPriority => $value,
            $value === null || $value === '' => LeadPriority::Medium,
            default => LeadPriority::from((int) $value),
        };
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        return $value === null || $value === '' ? null : CarbonImmutable::parse($value);
    }
}
