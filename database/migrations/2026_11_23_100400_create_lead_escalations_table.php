<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_escalations', function (Blueprint $table): void {
            $table->id();
            // UNIQUE: at most one escalation per lead. This is the idempotency anchor (R5.5).
            $table->foreignId('lead_id')->unique()->constrained('leads')->cascadeOnDelete();
            // NULL means the escalation fell back to the admins (assumption A-5).
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('escalated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_escalations');
    }
};
