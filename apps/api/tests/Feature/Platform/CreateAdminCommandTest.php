<?php

use App\Models\PlatformUser;

it('CA-13 creates a super admin', function () {
    $this->artisan('platform:create-admin', ['email' => 'Gustavo@Exemplo.com', 'name' => 'Gustavo', '--password' => 'uma-senha-forte-123'])
        ->assertSuccessful();

    $user = PlatformUser::where('email', 'gustavo@exemplo.com')->firstOrFail();
    expect($user->role)->toBe('super_admin')->and($user->active)->toBeTrue()->and($user->totp_confirmed_at)->toBeNull();
});

it('CA-13 refuses a duplicate email', function () {
    $args = ['email' => 'gustavo@exemplo.com', 'name' => 'Gustavo', '--password' => 'uma-senha-forte-123'];
    $this->artisan('platform:create-admin', $args)->assertSuccessful();

    $this->artisan('platform:create-admin', $args)->assertFailed();
});

it('CA-13 refuses a short password', function () {
    $this->artisan('platform:create-admin', ['email' => 'a@exemplo.com', 'name' => 'A', '--password' => 'curta'])->assertFailed();
});
