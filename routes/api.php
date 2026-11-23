<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\InboundLeadWebhookController;
use App\Http\Controllers\Api\V1\LeadBulkAssignmentController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\LeadStatusController;
use App\Http\Controllers\Api\V1\LeadStatusHistoryController;
use App\Http\Controllers\Api\V1\WebhookSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('auth/tokens', [AuthTokenController::class, 'store'])
        ->middleware('throttle:api-token')->name('tokens.store');

    Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function (): void {
        Route::delete('auth/tokens/current', [AuthTokenController::class, 'destroy'])->name('tokens.destroy');

        Route::post('leads/bulk-assign', [LeadBulkAssignmentController::class, 'store'])->name('leads.bulk-assign');
        Route::get('leads', [LeadController::class, 'index'])->name('leads.index');
        Route::post('leads', [LeadController::class, 'store'])->name('leads.store');
        Route::get('leads/{lead}', [LeadController::class, 'show'])->whereNumber('lead')->name('leads.show');
        Route::patch('leads/{lead}', [LeadController::class, 'update'])->whereNumber('lead')->name('leads.update');
        Route::patch('leads/{lead}/status', [LeadStatusController::class, 'update'])->whereNumber('lead')->name('leads.status');
        Route::get('leads/{lead}/status-history', [LeadStatusHistoryController::class, 'index'])->whereNumber('lead')->name('leads.history');

        Route::get('webhook-subscriptions', [WebhookSubscriptionController::class, 'index'])->name('webhook-subscriptions.index');
        Route::post('webhook-subscriptions', [WebhookSubscriptionController::class, 'store'])->name('webhook-subscriptions.store');
        Route::delete('webhook-subscriptions/{subscription}', [WebhookSubscriptionController::class, 'destroy'])
            ->whereNumber('subscription')->name('webhook-subscriptions.destroy');
    });

    // Inbound lead capture: authenticated by HMAC signature, not by a user token.
    Route::post('webhooks/leads', [InboundLeadWebhookController::class, 'store'])
        ->middleware(['throttle:webhooks', 'webhook.signature'])->name('webhooks.leads');
});
