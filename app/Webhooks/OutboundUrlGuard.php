<?php

declare(strict_types=1);

namespace App\Webhooks;

use App\Exceptions\Domain\InvalidWebhookUrl;

/**
 * SSRF protection for outbound webhooks (R12.9): only https URLs whose host
 * resolves exclusively to public addresses. Checked when a subscription is
 * saved AND again right before each delivery (DNS can change: rebinding).
 */
final readonly class OutboundUrlGuard
{
    /**
     * @param  \Closure(string): list<string>|null  $resolver  host -> IPs (injectable for tests)
     */
    public function __construct(
        private bool $allowPrivate = false,
        private ?\Closure $resolver = null,
    ) {}

    public function assertAllowed(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidWebhookUrl('The webhook URL must be an absolute http(s) URL.');
        }

        if ($this->allowPrivate) {
            return;
        }

        if ($scheme !== 'https') {
            throw new InvalidWebhookUrl('The webhook URL must use https.');
        }

        $ips = $this->resolve(trim($host, '[]'));

        if ($ips === []) {
            throw new InvalidWebhookUrl('The webhook host could not be resolved.');
        }

        foreach ($ips as $ip) {
            $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

            if ($public === false) {
                throw new InvalidWebhookUrl('The webhook URL points to a private or reserved address.');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if ($this->resolver !== null) {
            return ($this->resolver)($host);
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(
            static fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        )));
    }
}
