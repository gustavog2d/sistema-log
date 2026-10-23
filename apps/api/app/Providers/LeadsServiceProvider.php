<?php

namespace App\Providers;

use App\Leads\CaptchaVerifier;
use App\Leads\FakeCaptchaVerifier;
use App\Leads\RecaptchaVerifier;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class LeadsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CaptchaVerifier::class, function (): CaptchaVerifier {
            $driver = Config::string('leads.captcha_driver');

            // O verificador de teste aprova qualquer robô que conheça o token: nunca fora de desenvolvimento.
            if ($driver === 'fake' && ! $this->app->environment(['local', 'testing'])) {
                throw new InvalidArgumentException('O verificador de captcha de teste só pode ser usado nos ambientes local e testing.');
            }

            return match ($driver) {
                'fake' => new FakeCaptchaVerifier,
                'recaptcha' => new RecaptchaVerifier(Config::string('leads.recaptcha_secret'), Config::float('leads.recaptcha_min_score')),
                default => throw new InvalidArgumentException('Defina CAPTCHA_DRIVER com fake ou recaptcha.'),
            };
        });
    }
}
