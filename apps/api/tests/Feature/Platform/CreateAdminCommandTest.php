<?php

use App\Models\PlatformUser;
use Symfony\Component\Console\Exception\InvalidOptionException;

afterEach(fn () => putenv('PLATFORM_ADMIN_PASSWORD'));

it('CA-13 creates a super admin', function () {
    putenv('PLATFORM_ADMIN_PASSWORD=uma-senha-forte-123');

    $this->artisan('platform:create-admin', ['email' => 'Gustavo@Exemplo.com', 'name' => 'Gustavo'])
        ->assertSuccessful();

    $user = PlatformUser::where('email', 'gustavo@exemplo.com')->firstOrFail();
    expect($user->role)->toBe('super_admin')->and($user->active)->toBeTrue()->and($user->totp_confirmed_at)->toBeNull();
});

it('CA-13 refuses a duplicate email', function () {
    putenv('PLATFORM_ADMIN_PASSWORD=uma-senha-forte-123');
    $args = ['email' => 'gustavo@exemplo.com', 'name' => 'Gustavo'];
    $this->artisan('platform:create-admin', $args)->assertSuccessful();

    $this->artisan('platform:create-admin', $args)->assertFailed();
});

it('CA-13 refuses a short password', function () {
    putenv('PLATFORM_ADMIN_PASSWORD=curta');

    $this->artisan('platform:create-admin', ['email' => 'a@exemplo.com', 'name' => 'A'])->assertFailed();
});

it('CA-13 does not accept the password as a command line option', function () {
    expect(fn () => $this->artisan('platform:create-admin', ['email' => 'a@exemplo.com', 'name' => 'A', '--password' => 'uma-senha-forte-123']))
        ->toThrow(InvalidOptionException::class);
});
