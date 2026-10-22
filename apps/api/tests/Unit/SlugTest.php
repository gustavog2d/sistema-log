<?php

use App\Tenancy\Slug;

it('CA-15 accepts valid slugs', function (string $slug) {
    expect(Slug::isValid($slug))->toBeTrue();
})->with(['acme', 'meu-estudio', 'a1b', 'tatuagem-do-ze-2026']);

it('CA-15 rejects invalid slugs', function (string $slug) {
    expect(Slug::isValid($slug))->toBeFalse();
})->with(['ab', '-acme', 'acme-', 'Acme', 'a_b_c', 'com espaco', str_repeat('a', 31), 'acme.com', "acme\n", 'acme ', '']);

it('CA-15 rejects reserved slugs', function (string $slug) {
    expect(Slug::isValid($slug))->toBeFalse();
})->with(Slug::RESERVED);
