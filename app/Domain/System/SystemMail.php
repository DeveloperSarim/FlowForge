<?php

namespace App\Domain\System;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SystemMail extends Mailable
{
    public function __construct(public string $heading, public string $body, public ?string $url = null, public string $button = 'Open FlowForge') {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.system');
    }
}
