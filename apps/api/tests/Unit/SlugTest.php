<?php

use App\Tenancy\Slug;

it('accepts valid slugs', function (string $slug) {
    expect(Slug::isValid($slug))->toBeTrue();
})->with(['acme', 'meu-estudio', 'a1b', 'tatuagem-do-ze-2026']);

it('rejects invalid slugs', function (string $slug) {
    expect(Slug::isValid($slug))->toBeFalse();
})->with(['ab', '-acme', 'acme-', 'Acme', 'a_b_c', 'com espaco', str_repeat('a', 31), 'acme.com']);

it('rejects reserved slugs', function (string $slug) {
    expect(Slug::isValid($slug))->toBeFalse();
})->with(['www', 'admin', 'api', 'app', 'mail']);
