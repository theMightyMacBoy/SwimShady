<?php

namespace App\Console\Commands;

use App\Models\ApiRequest;
use App\Models\HistoryEntry;
use App\Models\IpRule;
use App\Models\LoginAttempt;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    protected $signature = 'swimshady:prune';

    protected $description = 'Delete old temperature history, request logs, and expired auto-bans';

    public function handle(): int
    {
        $historyDays = (int) config('swimshady.history_retention_days');
        if ($historyDays > 0) {
            $deleted = HistoryEntry::query()
                ->where('recorded_at', '<', now()->subDays($historyDays))
                ->delete();
            $this->info("Pruned {$deleted} history rows.");
        }

        $logDays = (int) config('swimshady.log_retention_days');
        if ($logDays > 0) {
            $cutoff = now()->subDays($logDays);
            $logins = LoginAttempt::query()->where('created_at', '<', $cutoff)->delete();
            $requests = ApiRequest::query()->where('created_at', '<', $cutoff)->delete();
            $bans = IpRule::query()
                ->where('source', IpRule::AUTO)
                ->where('created_at', '<', $cutoff)
                ->delete();
            $this->info("Pruned {$logins} login attempts, {$requests} API requests, {$bans} auto-bans.");
        }

        return self::SUCCESS;
    }
}
