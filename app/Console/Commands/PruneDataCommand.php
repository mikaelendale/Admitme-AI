<?php

namespace App\Console\Commands;

use App\Domain\Exports\ExportService;
use App\Models\AiContextSnapshot;
use App\Models\BrowserCommand;
use App\Models\BrowserSession;
use App\Models\BrowserSnapshot;
use App\Models\DataExport;
use App\Models\ExtensionLinkCode;
use Illuminate\Console\Command;

/**
 * Bounded retention: expired export archives are deleted, stuck exports are cleaned up, and operational
 * context snapshots and browser observations are kept only for a limited time. (Application logs rotate
 * daily; see LOG_DAILY_DAYS.)
 */
class PruneDataCommand extends Command
{
    protected $signature = 'admitme:prune-data';

    protected $description = 'Delete expired export archives and operational records past their retention period';

    public function handle(ExportService $exports): int
    {
        $expired = DataExport::query()->where('status', 'ready')->where('expires_at', '<', now())->get();
        $expired->each(fn (DataExport $export) => $exports->purge($export, 'expired', 'The download link expired; the archive was deleted.'));

        $stuck = DataExport::query()->whereIn('status', ['queued', 'building', 'failed'])->where('created_at', '<', now()->subDays(2))->get();
        $stuck->each(fn (DataExport $export) => $exports->purge($export, 'expired', 'Never finished; cleaned up.'));

        $snapshots = AiContextSnapshot::query()->where('created_at', '<', now()->subDays((int) config('admitme.retention.context_snapshot_days', 90)))->delete();

        // Browser observations hold what was on a portal form (values included): kept only briefly.
        $browserCutoff = now()->subDays((int) config('admitme.retention.browser_snapshot_days', 30));
        $observations = BrowserSnapshot::query()->where('created_at', '<', $browserCutoff)->delete();

        // So do the pages the side panel read for the mentor: the record of each action stays, what the page showed goes.
        $pageReads = BrowserCommand::query()->whereIn('type', BrowserCommand::AGENT_TYPES)->whereNotNull('result')->where('created_at', '<', $browserCutoff)->update(['result' => null]);
        // (updated_at is left alone: for a closed session it is when control was stopped.)
        BrowserSession::query()->where('mode', BrowserSession::MODE_AGENT)->where('status', 'closed')->where('updated_at', '<', $browserCutoff)->whereNotNull('current_url')->toBase()->update(['current_url' => null, 'current_title' => null]);

        $codes = ExtensionLinkCode::query()->where('expires_at', '<', now()->subDay())->delete();

        $this->info("Expired {$expired->count()} export(s), cleaned {$stuck->count()} unfinished export(s), deleted {$snapshots} context snapshot(s), {$observations} browser observation(s), {$pageReads} mentor page read(s) and {$codes} old link code(s).");

        return self::SUCCESS;
    }
}
