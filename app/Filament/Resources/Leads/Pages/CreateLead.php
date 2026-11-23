<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Pages;

use App\Actions\Leads\CreateLead as CreateLeadAction;
use App\Data\CreateLeadData;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateLead extends CreateRecord
{
    protected static string $resource = LeadResource::class;

    /**
     * Delegates to the application layer: Round Robin assignment, audit and
     * escalation scheduling all happen in the Action.
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        return app(CreateLeadAction::class)->handle(CreateLeadData::fromArray($data), $user);
    }

    /**
     * After creation the lead may be assigned to someone else and no longer be
     * visible to its creator (e.g. a manager outside the agent's team).
     */
    protected function getRedirectUrl(): string
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->can('view', $this->getRecord())
            ? LeadResource::getUrl('view', ['record' => $this->getRecord()])
            : LeadResource::getUrl('index');
    }
}
