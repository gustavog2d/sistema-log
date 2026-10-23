<?php

namespace App\TenantAuth;

/** Pessoa autenticada no negócio da requisição, com o token que a autenticou. */
final readonly class AuthenticatedPerson
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public string $role,
        public string $tokenId,
        public string $tokenKind,
        public bool $twoFactorEnabled,
        public ?string $impersonatedBy = null,
    ) {}

    /** @return array{id: string, name: string, email: string, role: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'email' => $this->email, 'role' => $this->role];
    }
}
