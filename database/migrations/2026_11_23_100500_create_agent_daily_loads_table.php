<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capacity ledger: one row per agent per business day. Reserving capacity is a
 * single conditional UPDATE on this row (design §6.3), never a COUNT over leads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_daily_loads', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('load_date');
            $table->unsignedSmallInteger('assigned_count')->default(0);

            $table->primary(['user_id', 'load_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_daily_loads');
    }
};
