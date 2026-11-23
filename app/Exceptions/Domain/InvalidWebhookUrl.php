<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class InvalidWebhookUrl extends DomainException
{
    public function errorCode(): string
    {
        return 'invalid_webhook_url';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
