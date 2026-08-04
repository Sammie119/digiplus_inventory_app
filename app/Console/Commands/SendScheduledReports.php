<?php

namespace App\Console\Commands;

use App\Mail\ScheduledSalesReportMail;
use App\Models\ScheduledReport;
use App\Reports\SalesReportData;
use App\Support\CurrentTenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendScheduledReports extends Command
{
    protected $signature = 'reports:send-scheduled';

    protected $description = 'Email any due scheduled sales reports';

    public function handle(): int
    {
        $schedules = ScheduledReport::withoutTenantScope()->where('report_type', 'sales')->get();

        $failures = 0;

        foreach ($schedules as $schedule) {
            if (! $this->isDue($schedule)) {
                continue;
            }

            try {
                CurrentTenant::set($schedule->tenant_id);

                [$from, $to] = $this->periodFor($schedule->frequency);
                $data = SalesReportData::build($schedule->tenant_id, $schedule->branch_id, $from, $to);

                $pdf = Pdf::loadView('pdf.sales-report', [
                    'tenantName' => $schedule->tenant->name,
                    'branchName' => $schedule->branch?->name ?? __('All Branches'),
                    'from' => $from,
                    'to' => $to,
                    'generatedAt' => now()->toDateTimeString(),
                    'summary' => $data['summary'],
                    'dailyBreakdown' => $data['dailyBreakdown'],
                ])->output();

                foreach ($schedule->recipients as $email) {
                    Mail::to($email)->send(new ScheduledSalesReportMail($schedule, $data['summary'], $from, $to, $pdf));
                }

                $schedule->update(['last_sent_at' => now()]);

                $this->info("Sent {$schedule->frequency} sales report for schedule #{$schedule->id} to ".count($schedule->recipients).' recipient(s).');
            } catch (\Throwable $e) {
                $failures++;

                Log::error('Scheduled sales report failed', [
                    'schedule_id' => $schedule->id,
                    'tenant_id' => $schedule->tenant_id,
                    'exception' => $e,
                ]);

                $this->error("Failed to send scheduled report #{$schedule->id} for tenant {$schedule->tenant_id}: {$e->getMessage()}");
            } finally {
                CurrentTenant::clear();
            }
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function isDue(ScheduledReport $schedule): bool
    {
        if (! $schedule->last_sent_at) {
            return true;
        }

        return match ($schedule->frequency) {
            'daily' => true,
            'weekly' => $schedule->last_sent_at->diffInDays(now()) >= 7,
            'monthly' => $schedule->last_sent_at->diffInMonths(now()) >= 1,
            default => false,
        };
    }

    private function periodFor(string $frequency): array
    {
        return match ($frequency) {
            'daily' => [now()->subDay()->toDateString(), now()->subDay()->toDateString()],
            'weekly' => [now()->subDays(7)->toDateString(), now()->subDay()->toDateString()],
            'monthly' => [
                now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ],
            default => [now()->toDateString(), now()->toDateString()],
        };
    }
}
