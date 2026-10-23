<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Convite para entrar na equipe de um negócio: texto simples com o link de uso único (o tema dos e-mails é do PLT-010). */
class InvitationMail extends Mailable
{
    public function __construct(
        public readonly string $businessName,
        public readonly string $inviteeName,
        public readonly string $link,
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Convite para a equipe de '.$this->businessName);
    }

    public function content(): Content
    {
        return new Content(text: 'emails.invitation');
    }
}
