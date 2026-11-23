<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum DeliveryStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Delivered = 'delivered';
    case Failed = 'failed';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
