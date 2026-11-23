<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\LeadPriority;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Performance dataset (R8.5): ~500,000 leads via chunked multi-row inserts
 * (no Eloquent, no events) in a few seconds. Run after the demo seeder:
 *
 *   php artisan db:seed --class=LargeLeadDatasetSeeder
 *
 * LEADS_SEED_COUNT can override the row count.
 */
final class LargeLeadDatasetSeeder extends Seeder
{
    private const int CHUNK = 5000;

    public function run(): void
    {
        $total = (int) env('LEADS_SEED_COUNT', 500_000);
        $agentIds = User::query()->activeAgents()->pluck('id')->all();
        $statuses = LeadStatus::values();
        $sources = LeadSource::values();
        $priorities = LeadPriority::values();
        $now = now();

        DB::disableQueryLog();
        $connection = DB::connection();

        for ($offset = 0; $offset < $total; $offset += self::CHUNK) {
            $rows = [];

            for ($i = 0; $i < min(self::CHUNK, $total - $offset); $i++) {
                $n = $offset + $i;
                $created = $now->copy()->subMinutes(random_int(0, 60 * 24 * 365));
                // ~10% unassigned, the rest spread over the active agents.
                $assignee = ($n % 10 === 0 || $agentIds === []) ? null : $agentIds[$n % count($agentIds)];

                $rows[] = [
                    'name' => 'Lead '.$n.' '.self::NAMES[$n % count(self::NAMES)],
                    'phone' => sprintf('+2012%08d', $n),
                    'source' => $sources[$n % count($sources)],
                    'priority' => $priorities[$n % count($priorities)],
                    'status' => $statuses[$n % count($statuses)],
                    'assigned_to' => $assignee,
                    'assigned_at' => $assignee === null ? null : $created,
                    'next_follow_up_at' => $created->copy()->addHours(random_int(1, 96)),
                    'created_at' => $created,
                    'updated_at' => $created,
                ];
            }

            $connection->table('leads')->insert($rows);
            $this->command?->getOutput()->write("\r  inserted ".number_format($offset + count($rows)).' / '.number_format($total));
        }

        $this->command?->newLine();
        $connection->statement('ANALYZE TABLE leads');
    }

    private const array NAMES = ['Ahmed Hassan', 'Mona Adel', 'Omar Khaled', 'Sara Mahmoud', 'Youssef Ali', 'Nour Ibrahim', 'Karim Fathy', 'Laila Samir'];
}
