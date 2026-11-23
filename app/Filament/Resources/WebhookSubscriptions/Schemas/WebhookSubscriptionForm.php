<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookSubscriptions\Schemas;

use App\Enums\WebhookEvent;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class WebhookSubscriptionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(100),
            TextInput::make('url')->label('Endpoint URL')->url()->required()->maxLength(2048)
                ->helperText('Must be https and resolve to a public address.'),
            CheckboxList::make('events')->options(WebhookEvent::options())->required()->columns(2),
            TextInput::make('secret')->password()->revealable()->minLength(16)
                ->helperText('Leave empty on create to generate one. Receivers verify X-Webhook-Signature with it.')
                ->dehydrated(fn (?string $state): bool => filled($state)),
            Toggle::make('is_active')->default(true)->hiddenOn('create'),
        ]);
    }
}
