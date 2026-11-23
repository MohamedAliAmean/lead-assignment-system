<?php

declare(strict_types=1);

use App\Enums\DeliveryStatus;
use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('url', 2048);
            $table->text('secret'); // encrypted at rest (model cast)
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary(); // also sent as X-Webhook-Id
            $table->foreignId('subscription_id')->constrained('webhook_subscriptions')->cascadeOnDelete();
            $table->string('event', 50);
            $table->uuid('event_id');
            $table->json('payload');
            $table->string('status', 20)->default(DeliveryStatus::Pending->value);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            // One delivery per event per subscription, even if the listener retries (R12.8).
            $table->unique(['subscription_id', 'event_id'], 'uq_deliveries_subscription_event');
            $table->index(['status', 'created_at'], 'idx_deliveries_status_created');
        });

        CheckConstraint::forEnum('webhook_deliveries', 'status', DeliveryStatus::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_subscriptions');
    }
};
