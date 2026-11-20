<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneActivity extends Command
{
    protected $signature = 'activity:prune';

    protected $description = 'Delete access ("auth") rows of the audit trail older than the retention period. Administrative actions are kept.';

    public function handle(): int
    {
        $days = max(1, (int) config('shop.activity.auth_retention_days', 180));
        // Query builder on purpose: the ActivityLog model refuses to delete. Only module "auth" can ever be removed.
        $deleted = DB::table('activity_logs')->where('module', 'auth')->where('occurred_at', '<', now()->subDays($days))->delete();

        $this->info("Se borraron {$deleted} registros de accesos con más de {$days} días.");

        return self::SUCCESS;
    }
}
