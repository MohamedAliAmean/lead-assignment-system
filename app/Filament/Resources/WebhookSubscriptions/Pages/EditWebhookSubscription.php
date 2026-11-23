<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookSubscriptions\Pages;

use App\Exceptions\Domain\InvalidWebhookUrl;
use App\Filament\Resources\WebhookSubscriptions\WebhookSubscriptionResource;
use App\Webhooks\OutboundUrlGuard;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

final class EditWebhookSubscription extends EditRecord
{
    protected static string $resource = WebhookSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        try {
            app(OutboundUrlGuard::class)->assertAllowed($data['url']);
        } catch (InvalidWebhookUrl $e) {
            throw ValidationException::withMessages(['data.url' => $e->getMessage()]);
        }

        return $data;
    }
}
