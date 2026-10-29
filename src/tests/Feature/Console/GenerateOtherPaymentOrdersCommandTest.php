<?php

namespace Tests\Feature\Console;

use App\Actions\PaymentOrders\Other\GenerateOtherPaymentOrders;
use DomainException;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class GenerateOtherPaymentOrdersCommandTest extends TestCase
{
    public function test_reports_how_many_orders_were_generated_and_fails_when_the_lock_is_busy(): void
    {
        $mock = $this->mock(GenerateOtherPaymentOrders::class);
        $mock->shouldReceive('all')->once()->andReturn(2);
        $mock->shouldReceive('all')->once()->andReturn(0);
        $mock->shouldReceive('all')->once()->andThrow(new DomainException('Outra geração de ordens está em andamento. Tente novamente.'));

        $this->artisan('ordens-pagamento-outros:gerar')->expectsOutput('2 ordem(ns) de pagamento gerada(s) com sucesso!')->assertExitCode(0);
        $this->artisan('ordens-pagamento-outros:gerar')->expectsOutput('Nenhuma ordem de pagamento gerada!')->assertExitCode(0);
        $this->artisan('ordens-pagamento-outros:gerar')->assertExitCode(1);
    }

    public function test_is_scheduled_only_when_the_automatic_generation_is_on(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'ordens-pagamento-outros:gerar'));

        config(['services.ordem_pagamento_outros.automatic_generation' => false]);
        $this->assertFalse($event->filtersPass($this->app));

        config(['services.ordem_pagamento_outros.automatic_generation' => true]);
        $this->assertTrue($event->filtersPass($this->app));
    }
}
