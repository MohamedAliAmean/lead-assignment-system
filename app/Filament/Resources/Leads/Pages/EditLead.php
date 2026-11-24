<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Pages;

use App\Actions\Leads\UpdateLead;
use App\Data\UpdateLeadData;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditLead extends EditRecord
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    /**
     * @param  Lead  $record
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        return app(UpdateLead::class)->handle($record, UpdateLeadData::fromArray($data), $user);
    }
}
