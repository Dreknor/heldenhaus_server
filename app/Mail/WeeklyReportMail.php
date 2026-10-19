<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WeeklyReportMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly array $report) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Heldenhaus-Wochenbericht: {$this->report['family']}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.weekly-report', with: ['report' => $this->report, 'url' => route('family.report')]);
    }
}
