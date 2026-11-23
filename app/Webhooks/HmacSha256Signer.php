<?php

declare(strict_types=1);

namespace App\Webhooks;

use App\Webhooks\Contracts\WebhookSigner;

/**
 * signature = "sha256=" . HMAC_SHA256(secret, "{timestamp}.{body}")
 *
 * The timestamp is part of the signed message, so it cannot be altered to
 * replay an old request inside the tolerance window.
 */
final class HmacSha256Signer implements WebhookSigner
{
    public function sign(string $secret, int $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public function verify(string $secret, int $timestamp, string $body, string $signature): bool
    {
        // Constant-time comparison: no timing side channel.
        return hash_equals($this->sign($secret, $timestamp, $body), $signature);
    }
}
