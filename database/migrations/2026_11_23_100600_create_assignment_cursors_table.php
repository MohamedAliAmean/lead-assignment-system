<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Round Robin state. The "global" row is locked FOR UPDATE on every automatic
 * assignment, which serializes the rotation (design §6.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_cursors', function (Blueprint $table): void {
            $table->id();
            $table->string('pool', 50)->unique();
            $table->foreignId('last_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->nullable();
        });

        // The row we lock must always exist.
        DB::table('assignment_cursors')->insert(['pool' => 'global', 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_cursors');
    }
};
