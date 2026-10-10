<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A document (invoice, credit note, quotation, progress billing, statement)
 * sent to a customer with its PDF attached. Sent from the platform address
 * under the company's name, with replies going to the company.
 */
class DocumentMail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $subjectLine,
        public string $body,
        public string $companyName,
        public ?string $replyToAddress,
        public string $pdf,
        public string $filename,
        public ?string $link = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), $this->companyName),
            replyTo: $this->replyToAddress ? [new Address($this->replyToAddress, $this->companyName)] : [],
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.document');
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->filename)->withMime('application/pdf')];
    }
}
