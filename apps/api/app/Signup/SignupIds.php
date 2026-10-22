<?php

namespace App\Signup;

use Illuminate\Support\Str;

/** Identificadores gerados antes de gravar, para a resposta e a auditoria não dependerem do banco. */
final readonly class SignupIds
{
    public string $tenant;

    public string $user;

    public string $method;

    public string $subscription;

    public function __construct()
    {
        $this->tenant = (string) Str::uuid();
        $this->user = (string) Str::uuid();
        $this->method = (string) Str::uuid();
        $this->subscription = (string) Str::uuid();
    }
}
