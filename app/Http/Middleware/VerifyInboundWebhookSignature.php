<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Webhooks\Contracts\WebhookSigner;
use App\Webhooks\InboundSourceRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates inbound lead webhooks (R12.2): known source, fresh timestamp,
 * valid HMAC over "{timestamp}.{raw body}".
 */
final readonly class VerifyInboundWebhookSignature
{
    public function __construct(
        private InboundSourceRegistry $sources,
        private WebhookSigner $signer,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $source = (string) $request->header('X-Webhook-Source');
        $timestamp = (string) $request->header('X-Webhook-Timestamp');
        $signature = (string) $request->header('X-Webhook-Signature');
        $secret = $this->sources->secretFor($source);

        if ($secret === null || $signature === '' || ! ctype_digit($timestamp)) {
            return $this->reject('Unknown source or missing signature headers.');
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > (int) config('webhooks.inbound.timestamp_tolerance')) {
            return $this->reject('Stale or future timestamp.');
        }

        if (! $this->signer->verify($secret, (int) $timestamp, $request->getContent(), $signature)) {
            return $this->reject('Invalid signature.');
        }

        return $next($request);
    }

    private function reject(string $message): Response
    {
        return response()->json(['message' => $message, 'code' => 'invalid_webhook_signature'], 401);
    }
}
