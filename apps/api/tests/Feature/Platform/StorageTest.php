<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

it('CA-11 stores the password hashed, the totp secret encrypted and recovery codes hashed', function () {
    $user = $this->platformUser();
    $user->recovery_codes = [Hash::make('abcd-efgh-1234')];
    $user->save();

    $row = DB::connection('pgsql_admin')->table('platform_users')->where('id', $user->id)->first();

    expect($row->password)->not->toBe(self::PASSWORD);
    expect(Hash::check(self::PASSWORD, $row->password))->toBeTrue();
    expect($row->totp_secret)->not->toContain((string) $user->totp_secret);
    expect($row->recovery_codes)->not->toContain('abcd-efgh-1234');
});

it('CA-11 does not expose secrets in the model array', function () {
    $user = $this->platformUser();

    expect(array_keys($user->toArray()))->not->toContain('password', 'totp_secret', 'recovery_codes');
});
