<?php

namespace Database\Factories;

use App\Models\PlatformUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use PragmaRX\Google2FA\Google2FA;

/** @extends Factory<PlatformUser> */
class PlatformUserFactory extends Factory
{
    protected $model = PlatformUser::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'senha-segura-123',
            'role' => 'super_admin',
            'active' => true,
        ];
    }

    public function withTwoFactor(): static
    {
        return $this->afterCreating(function (PlatformUser $user) {
            $user->forceFill([
                'totp_secret' => (new Google2FA)->generateSecretKey(),
                'totp_confirmed_at' => now(),
            ])->save();
        });
    }
}
