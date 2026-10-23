<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * E-mail de aviso da plataforma (texto simples). O tipo escolhe o assunto e o texto; nunca carrega senha,
 * token, número de cartão ou referência do gateway (RN-04).
 */
class TransactionalMail extends Mailable
{
    use Queueable;

    public const SUBJECTS = [
        'welcome' => 'Bem-vindo(a)!',
        'trial_ending' => 'Seu período de teste está acabando',
        'payment_succeeded' => 'Pagamento confirmado',
        'subscription_suspended' => 'Sua assinatura foi suspensa',
        'subscription_regularized' => 'Pagamento regularizado',
        'cancel_requested' => 'Cancelamento da assinatura registrado',
        'subscription_canceled' => 'Sua assinatura foi encerrada',
        'password_changed' => 'Sua senha foi alterada',
        'two_factor_disabled' => 'A verificação em duas etapas foi desligada',
    ];

    /** @param  array<string, string>  $data  só texto já formatado (valor, data, motivo, endereço) */
    public function __construct(
        public readonly string $kind,
        public readonly string $recipientName,
        public readonly string $businessName,
        public readonly array $data = [],
    ) {}

    /** Tentativas do envio na fila (a espera cresce a cada falha). */
    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function envelope(): Envelope
    {
        // O nome do negócio é texto livre de quem se cadastrou: no assunto só entra depois que a pessoa é conhecida (não no boas-vindas).
        return new Envelope(subject: $this->kind === 'welcome' ? self::SUBJECTS['welcome'] : self::SUBJECTS[$this->kind].' · '.$this->businessName);
    }

    public function content(): Content
    {
        $view = 'emails.notifications.'.$this->kind;

        // @phpstan-ignore argument.type (o nome da visão é montado pelo tipo, que só pode ser uma das chaves de SUBJECTS)
        return new Content(text: $view, with: ['product' => config('app.name'), 'data' => $this->data]);
    }
}
