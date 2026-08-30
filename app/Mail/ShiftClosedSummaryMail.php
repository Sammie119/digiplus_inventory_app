<?php

namespace App\Mail;

use App\Models\Shift;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ShiftClosedSummaryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Shift $shift, public array $summary) {}

    public function build(): static
    {
        return $this
            ->subject(__('Shift Closed — :cashier (:branch)', [
                'cashier' => $this->summary['cashier_name'],
                'branch' => $this->summary['branch_name'],
            ]))
            ->markdown('mail.shift-closed-summary', ['summary' => $this->summary]);
    }
}
