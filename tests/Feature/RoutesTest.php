<?php

namespace RiseTechApps\Monitoring\Tests\Feature;

use RiseTechApps\Monitoring\Monitoring;
use RiseTechApps\Monitoring\Tests\TestCase;

/** As rotas expõem logs da aplicação inteira: autenticadas por padrão. */
class RoutesTest extends TestCase
{
    public function test_routes_require_authentication_by_default(): void
    {
        Monitoring::routes();

        $this->getJson('/monitoring')->assertUnauthorized();
        $this->getJson('/monitoring/user/1')->assertUnauthorized();
    }
}
