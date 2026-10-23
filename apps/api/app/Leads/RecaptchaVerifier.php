<?php

namespace App\Leads;

use Illuminate\Support\Facades\Http;
use Throwable;

/** reCAPTCHA do Google. A chave secreta vem de configuração; qualquer falha do serviço recusa o pedido. */
class RecaptchaVerifier implements CaptchaVerifier
{
    public function __construct(private readonly string $secret, private readonly float $minScore = 0.5) {}

    public function verify(string $token, ?string $ip): bool
    {
        if ($this->secret === '' || $token === '') {
            return false;
        }

        try {
            $response = Http::asForm()->connectTimeout(2)->timeout(5)->post('https://www.google.com/recaptcha/api/siteverify', array_filter([
                'secret' => $this->secret, 'response' => $token, 'remoteip' => $ip,
            ]));

            if (! $response->ok() || $response->json('success') !== true) {
                return false;
            }

            // A versão 3 devolve nota; a 2 não. Com nota, exige o mínimo.
            $score = $response->json('score');

            return ! is_numeric($score) || (float) $score >= $this->minScore;
        } catch (Throwable) {
            return false;
        }
    }
}
