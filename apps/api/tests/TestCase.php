<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Fixo nos testes, independente do APP_BASE_DOMAIN do ambiente.
        config(['tenancy.base_domain' => 'app.test']);
    }
}
