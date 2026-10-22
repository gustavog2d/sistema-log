<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

function currentDatabaseTenant(): ?string
{
    $value = DB::selectOne("select nullif(current_setting('app.tenant_id', true), '') as tenant")->tenant;

    return $value === null ? null : (string) $value;
}

it('CA-11 clears the database tenant after a request', function () {
    $this->makeTenant('acme');

    $this->getJson('http://acme.app.test/api/v1/tenant/current')->assertOk();

    expect(currentDatabaseTenant())->toBeNull();
});

it('CA-11 clears the database tenant even when the request fails', function () {
    Route::middleware('tenant')->get('api/v1/_probe/explode', fn () => throw new RuntimeException('boom'));
    $this->makeTenant('acme');

    $this->getJson('http://acme.app.test/api/v1/_probe/explode')->assertStatus(500);

    expect(currentDatabaseTenant())->toBeNull();
});
