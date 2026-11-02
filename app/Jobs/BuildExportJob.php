<?php

namespace App\Jobs;

use App\Domain\Exports\ExportService;
use App\Models\DataExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Packages a captured export snapshot into its private archive.
 */
class BuildExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public string $exportId)
    {
        $this->afterCommit();
    }

    public function handle(ExportService $exports): void
    {
        $export = DataExport::query()->find($this->exportId);

        if ($export !== null) {
            $exports->build($export);
        }
    }

    public function failed(?Throwable $e): void
    {
        DataExport::query()->whereKey($this->exportId)->update(['status' => 'failed', 'status_reason' => 'The archive could not be built. Request a new export.']);
    }
}
