<?php

use Symfony\Component\Yaml\Yaml;

function platformSchema(string $name): array
{
    $spec = Yaml::parseFile(base_path('../../docs/funcionalidades/PLT-001-login-super-admin/openapi.yaml'));

    return array_map('strval', array_keys(data_get($spec, "components.schemas.{$name}.properties")));
}

it('returns bodies that match the openapi contract', function () {
    $user = $this->platformUser();

    $challenge = $this->login($user->email)->assertOk();
    expect(array_keys($challenge->json()))->toEqualCanonicalizing(platformSchema('Challenge'));

    $access = $this->asToken($challenge->json('challenge_token'), 'POST', 'auth/2fa/verify', ['code' => $this->otp((string) $user->totp_secret)]);
    expect(array_keys($access->json()))->toEqualCanonicalizing(platformSchema('AccessToken'));

    $me = $this->asToken($access->json('access_token'), 'GET', 'me');
    expect(array_keys($me->json()))->toEqualCanonicalizing(platformSchema('PlatformUser'));
});

it('returns the setup and confirm bodies from the contract', function () {
    $user = $this->platformUser(twoFactor: false);
    $challenge = $this->login($user->email)->json('challenge_token');

    $setup = $this->asToken($challenge, 'POST', '2fa/setup');
    expect(array_keys($setup->json()))->toEqualCanonicalizing(platformSchema('TwoFactorSetup'));

    $confirmed = $this->asToken($challenge, 'POST', '2fa/confirm', ['code' => $this->otp($setup->json('secret'))]);
    expect(array_keys($confirmed->json()))->toEqualCanonicalizing(platformSchema('TwoFactorConfirmed'));
});
