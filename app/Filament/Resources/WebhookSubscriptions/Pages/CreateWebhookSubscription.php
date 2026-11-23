<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookSubscriptions\Pages;

use App\Actions\Webhooks\CreateWebhookSubscription as CreateSubscriptionAction;
use App\Enums\WebhookEvent;
use App\Exceptions\Domain\InvalidWebhookUrl;
use App\Filament\Resources\WebhookSubscriptions\WebhookSubscriptionResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class CreateWebhookSubscription extends CreateRecord
{
    protected static string $resource = WebhookSubscriptionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        try {
            return app(CreateSubscriptionAction::class)->handle(
                $user,
                $data['name'],
                $data['url'],
                array_map(static fn (string $event): WebhookEvent => WebhookEvent::from($event), $data['events']),
                $data['secret'] ?? null,
            );
        } catch (InvalidWebhookUrl $e) {
            throw ValidationException::withMessages(['data.url' => $e->getMessage()]);
        }
    }
}
