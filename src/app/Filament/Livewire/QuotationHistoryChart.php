<?php

namespace App\Filament\Livewire;

use App\Actions\Reports\QuotationHistoryChartData;
use App\Filament\Pages\QuotationHistory;
use Filament\Widgets\ChartWidget;
use Livewire\Attributes\Locked;

/** Gráfico do "Histórico de cotações": o resumo dos filtros enviados, reagrupado pelas opções do gráfico escolhidas na página. */
class QuotationHistoryChart extends ChartWidget
{
    private const COLORS = ['#2563eb', '#dc2626', '#16a34a', '#d97706', '#7c3aed', '#0891b2', '#db2777', '#65a30d'];

    protected ?string $heading = 'Gráfico';

    protected ?string $maxHeight = '320px';

    /** @var array<string, mixed> */
    #[Locked]
    public array $applied = [];

    /** @var array<string, mixed> */
    #[Locked]
    public array $chartOptions = [];

    /**
     * @param  array<string, mixed>  $applied  filtros do último "Enviar"
     * @param  array<string, mixed>  $chartOptions  opções do gráfico (QuotationHistoryChartData)
     */
    public function mount(array $applied = [], array $chartOptions = []): void
    {
        $this->applied = $applied;
        $this->chartOptions = $chartOptions;

        parent::mount();
    }

    protected function getType(): string
    {
        return $this->chart()['type'] === 'linha' ? 'line' : 'bar';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $chart = $this->chart();

        return [
            'labels' => $chart['categories'],
            'datasets' => array_map(fn (array $series, int $index) => [
                'label' => $series['name'],
                'data' => $series['values'],
                'borderColor' => $color = self::COLORS[$index % count(self::COLORS)],
                'backgroundColor' => $chart['type'] === 'linha' ? $color.'33' : $color,
                'spanGaps' => true,
            ], $chart['series'], array_keys($chart['series'])),
        ];
    }

    /** @return array{type: string, categories: list<string>, series: list<array{name: string, values: list<float|int|null>}>} */
    private function chart(): array
    {
        $report = QuotationHistory::run($this->applied);

        return app(QuotationHistoryChartData::class)($report['resumo'], $report['agrupamento'], $this->chartOptions);
    }
}
