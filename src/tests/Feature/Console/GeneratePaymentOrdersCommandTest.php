<?php

namespace Tests\Feature\Console;

use App\Actions\PaymentOrders\Generation\GeneratePaymentOrders;
use DomainException;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class GeneratePaymentOrdersCommandTest extends TestCase
{
    public function test_reports_how_many_orders_were_generated(): void
    {
        $this->mock(GeneratePaymentOrders::class)->shouldReceive('all')->once()->andReturn(2);

        $this->artisan('ordens-pagamento:gerar')->expectsOutput('2 ordem(ns) de pagamento gerada(s) com sucesso!')->assertExitCode(0);
    }

    public function test_says_so_when_nothing_was_generated_and_fails_when_the_lock_is_busy(): void
    {
        $mock = $this->mock(GeneratePaymentOrders::class);
        $mock->shouldReceive('all')->once()->andReturn(0);
        $mock->shouldReceive('all')->once()->andThrow(new DomainException('Outra geração de ordens está em andamento. Tente novamente.'));

        $this->artisan('ordens-pagamento:gerar')->expectsOutput('Nenhuma ordem de pagamento gerada!')->assertExitCode(0);
        $this->artisan('ordens-pagamento:gerar')->assertExitCode(1);
    }

    public function test_is_scheduled_only_when_the_automatic_generation_is_on(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'ordens-pagamento:gerar'));

        config(['services.ordem_pagamento.automatic_generation' => false]);
        $this->assertFalse($event->filtersPass($this->app));

        config(['services.ordem_pagamento.automatic_generation' => true]);
        $this->assertTrue($event->filtersPass($this->app));
    }
}
