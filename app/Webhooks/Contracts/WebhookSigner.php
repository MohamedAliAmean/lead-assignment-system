<?php

declare(strict_types=1);

namespace App\Webhooks\Contracts;

/**
 * Signs and verifies webhook payloads. Used by both inbound verification and
 * outbound delivery, so both directions share one scheme.
 */
interface WebhookSigner
{
    public function sign(string $secret, int $timestamp, string $body): string;

    public function verify(string $secret, int $timestamp, string $body, string $signature): bool;
}
