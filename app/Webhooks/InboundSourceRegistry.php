<?php

declare(strict_types=1);

namespace App\Webhooks;

/**
 * Known inbound lead sources and their shared secrets (config/webhooks.php).
 */
final readonly class InboundSourceRegistry
{
    /**
     * @param  array<string, string>  $secrets  source => secret
     */
    public function __construct(private array $secrets) {}

    public function secretFor(string $source): ?string
    {
        $secret = $this->secrets[$source] ?? null;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
