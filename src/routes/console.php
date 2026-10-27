<?php

use App\Actions\Cobranca\Generation\GenerateAllCharges;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// executeGeraCobranca (legado): gera as ICs de todas as reservas com despesas pendentes.
Artisan::command('cobranca:gerar', function () {
    try {
        $created = app(GenerateAllCharges::class)(null);
    } catch (DomainException $e) {
        $this->warn($e->getMessage());

        return 1;
    }

    $this->info($created ? "{$created} cobrança(s) gerada(s) com sucesso!" : 'Nenhuma cobrança gerada!');

    return 0;
})->purpose('Gera as Instruções de Cobrança das reservas com despesas pendentes');

// desligado até o cron do tracking parar de chamar cobranca/geraCobranca (COBRANCA_GERACAO_AUTOMATICA=true)
Schedule::command('cobranca:gerar')
    ->hourly()
    ->withoutOverlapping()
    ->when(fn () => (bool) config('services.cobranca.automatic_generation'));
