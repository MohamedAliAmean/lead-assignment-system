<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_status_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            // NULL = changed by the system (job, scheduler) - R4.4.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // NULL on the initial "created" entry.
            $table->string('old_status', 20)->nullable();
            $table->string('new_status', 20);
            // Millisecond precision keeps fast successive changes in order.
            $table->timestamp('changed_at', 3);

            // History of one lead, already ordered. Replaces the plain FK index.
            $table->index(['lead_id', 'changed_at'], 'idx_status_logs_lead_changed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_status_logs');
    }
};
