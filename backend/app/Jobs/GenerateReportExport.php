<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Services\ReportExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $exportId) {}

    public function handle(ReportExportService $service): void
    {
        $export = ReportExport::with('tenant', 'requester')->find($this->exportId);
        if (! $export || $export->status !== 'queued') {
            return;
        }
        $export->update(['status' => 'processing', 'started_at' => now()]);
        try {
            $service->write($export);
        } catch (\Throwable $e) {
            $export->update(['status' => 'failed', 'failure_reason' => 'The export could not be generated.', 'completed_at' => now()]);
            throw $e;
        }
    }
}
