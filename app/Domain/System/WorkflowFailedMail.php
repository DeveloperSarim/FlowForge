<?php

namespace App\Domain\System;

use App\Domain\Workflows\Models\WorkflowExecution;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WorkflowFailedMail extends Mailable
{
    public function __construct(public WorkflowExecution $execution, public int $suppressed = 0) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Workflow failed: '.($this->execution->workflow?->name ?? 'deleted workflow'));
    }

    public function content(): Content
    {
        $failed = collect($this->execution->steps ?? [])->firstWhere('status', 'failed');

        return new Content(markdown: 'mail.workflow-failed', with: [
            'workflow' => $this->execution->workflow,
            'step' => $failed,
            'url' => url('/executions?open='.$this->execution->id),
        ]);
    }
}
