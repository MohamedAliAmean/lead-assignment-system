<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\LeadPriority;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;

/**
 * Partial lead update: only the fields that were actually sent are carried,
 * so the DTO has no knowledge of the current Lead (no dependency on the model).
 * UpdateLead applies exactly the provided fields.
 */
final readonly class UpdateLeadData
{
    /**
     * @param  array<string, mixed>  $changes  attribute => normalized value (only provided fields)
     */
    private function __construct(
        private array $changes,
        public ?LeadStatus $status,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated input; absent keys are left untouched
     */
    public static function fromArray(array $data): self
    {
        $changes = [];

        if (array_key_exists('name', $data)) {
            $changes['name'] = (string) $data['name'];
        }
        if (array_key_exists('phone', $data)) {
            $changes['phone'] = PhoneNumber::normalize((string) $data['phone']);
        }
        if (array_key_exists('source', $data)) {
            $changes['source'] = $data['source'] instanceof LeadSource ? $data['source'] : LeadSource::from((string) $data['source']);
        }
        if (array_key_exists('priority', $data)) {
            $changes['priority'] = $data['priority'] instanceof LeadPriority ? $data['priority'] : LeadPriority::from((int) $data['priority']);
        }
        if (array_key_exists('next_follow_up_at', $data)) {
            $changes['next_follow_up_at'] = self::date($data['next_follow_up_at']);
        }

        $status = match (true) {
            ! array_key_exists('status', $data), $data['status'] === null, $data['status'] === '' => null,
            $data['status'] instanceof LeadStatus => $data['status'],
            default => LeadStatus::from((string) $data['status']),
        };

        return new self($changes, $status);
    }

    /**
     * Attribute changes to apply (status excluded: it has its own use case).
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return $this->changes;
    }

    /**
     * Parses and normalizes to UTC. Eloquent stores a datetime's wall-clock time
     * as-is, so "10:00+02:00" must become 08:00Z before it reaches the model.
     */
    private static function date(mixed $value): ?CarbonImmutable
    {
        return $value === null || $value === '' ? null : CarbonImmutable::parse($value)->utc();
    }
}
