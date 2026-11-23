<?php

declare(strict_types=1);

use App\Filament\Resources\Teams\TeamResource;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Livewire\Livewire;

it('lets admins change an agent\'s capacity and active flag', function (): void {
    $agent = User::factory()->agent()->capacity(5)->create();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(EditUser::class, ['record' => $agent->getRouteKey()])
        ->fillForm(['daily_capacity' => 12, 'is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($agent->fresh())->daily_capacity->toBe(12)->is_active->toBeFalse();
});

it('keeps managers and agents out of user and team administration', function (string $role): void {
    $this->actingAs(User::factory()->{$role}()->create());

    $this->get(UserResource::getUrl('index'))->assertForbidden();
    $this->get(TeamResource::getUrl('index'))->assertForbidden();
})->with(['manager', 'agent']);
