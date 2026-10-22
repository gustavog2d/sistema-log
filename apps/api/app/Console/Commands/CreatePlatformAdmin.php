<?php

namespace App\Console\Commands;

use App\Models\PlatformUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;

class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform:create-admin
        {email : E-mail de acesso}
        {name : Nome da pessoa}
        {--password= : Senha (se omitida, é pedida sem eco)}';

    protected $description = 'Cria um super admin da plataforma (o 2FA é ativado no primeiro acesso)';

    public function handle(): int
    {
        $email = mb_strtolower((string) $this->argument('email'));
        $minLength = Config::integer('platform.min_password_length');
        $password = (string) ($this->option('password') ?: password('Senha (mínimo '.$minLength.' caracteres)'));

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email', 'unique:platform_users,email'], 'password' => ['required', 'string', 'min:'.$minLength]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        PlatformUser::create([
            'name' => (string) $this->argument('name'),
            'email' => $email,
            'password' => $password,
            'role' => 'super_admin',
        ]);

        $this->info("Super admin {$email} criado. O 2FA será configurado no primeiro acesso.");

        return self::SUCCESS;
    }
}
