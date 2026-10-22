<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Banco exclusivo dos testes: a suíte recria e trunca tabelas. */
    public const TEST_DATABASE = 'app_test';

    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        // A variável de ambiente do contêiner (DB_DATABASE=app) vence o phpunit.xml; por isso o
        // banco de teste é fixado aqui, na configuração, antes de qualquer conexão ser aberta.
        foreach (['pgsql', 'pgsql_admin', 'pgsql_platform', 'pgsql_auth'] as $connection) {
            $app['config']->set("database.connections.{$connection}.database", self::TEST_DATABASE);
        }

        // Fixo nos testes, independente do APP_BASE_DOMAIN do ambiente.
        $app['config']->set('tenancy.base_domain', 'app.test');

        return $app;
    }
}
