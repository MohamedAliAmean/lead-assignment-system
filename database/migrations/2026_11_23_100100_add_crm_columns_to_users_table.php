<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 20)->default(UserRole::SalesAgent->value)->after('password');
            $table->foreignId('team_id')->nullable()->after('role')->constrained('teams')->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('team_id');
            $table->unsignedSmallInteger('daily_capacity')->default(20)->after('is_active');
            // Reserved for weighted Round Robin (assumption A-10).
            $table->unsignedTinyInteger('assignment_weight')->default(1)->after('daily_capacity');

            // Covers the eligible-agent lookup: role = agent AND is_active = 1 ORDER BY id.
            $table->index(['role', 'is_active', 'id'], 'idx_users_role_active');
        });

        CheckConstraint::forEnum('users', 'role', UserRole::class);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `users` DROP CHECK `chk_users_role`');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('idx_users_role_active');
            $table->dropConstrainedForeignId('team_id');
            $table->dropColumn(['role', 'is_active', 'daily_capacity', 'assignment_weight']);
        });
    }
};
