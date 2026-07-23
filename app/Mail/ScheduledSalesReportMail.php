<?php

namespace App\Mail;

use App\Models\ScheduledReport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ScheduledSalesReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ScheduledReport $schedule,
        public array $summary,
        public string $periodFrom,
        public string $periodTo,
        public string $pdf,
    ) {}

    public function build(): static
    {
        return $this
            ->subject(__('Sales Report: :from to :to', ['from' => $this->periodFrom, 'to' => $this->periodTo]))
            ->markdown('mail.scheduled-sales-report', [
                'branchName' => $this->schedule->branch?->name ?? __('All Branches'),
                'from' => $this->periodFrom,
                'to' => $this->periodTo,
                'summary' => $this->summary,
            ])
            ->attachData($this->pdf, "sales-report-{$this->periodFrom}-to-{$this->periodTo}.pdf", [
                'mime' => 'application/pdf',
            ]);
    }
}
