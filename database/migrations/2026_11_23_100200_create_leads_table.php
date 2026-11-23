<?php

declare(strict_types=1);

use App\Enums\LeadPriority;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index rationale: design.md §4.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('phone', 32);
            $table->string('source', 30);
            $table->string('external_ref', 100)->nullable();
            $table->unsignedTinyInteger('priority')->default(LeadPriority::Medium->value);
            $table->string('status', 20)->default(LeadStatus::New->value);
            // Users are deactivated, never deleted, so RESTRICT protects history.
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->timestamps();

            // Agent / manager scopes, "My Leads", "Unassigned", status within scope. Also backs the FK.
            $table->index(['assigned_to', 'status'], 'idx_leads_assignee_status');
            // "Overdue" filter and sorting by follow-up time.
            $table->index('next_follow_up_at', 'idx_leads_follow_up');
            // Escalation sweeper (status = new AND created_at <= ?) and admin status filter.
            $table->index(['status', 'created_at'], 'idx_leads_status_created');
            // "High Priority" filter; InnoDB appends the PK, so ORDER BY id needs no filesort.
            $table->index('priority', 'idx_leads_priority');
            // Inbound webhook de-duplication (R12.3). Multiple NULLs are allowed.
            $table->unique(['source', 'external_ref'], 'uq_leads_source_external');
            // Phone prefix search.
            $table->index('phone', 'idx_leads_phone');
            // Name search without a full table scan.
            $table->fullText('name', 'ft_leads_name');
        });

        CheckConstraint::forEnum('leads', 'status', LeadStatus::class);
        CheckConstraint::forEnum('leads', 'priority', LeadPriority::class);
        CheckConstraint::forEnum('leads', 'source', LeadSource::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
