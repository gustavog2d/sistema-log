<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** E-mail de recuperação de senha: texto simples com o link de uso único (o tema dos e-mails é do PLT-010). */
class PasswordResetMail extends Mailable
{
    public function __construct(
        public readonly string $businessName,
        public readonly string $link,
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Redefinição de senha');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.password-reset');
    }
}
