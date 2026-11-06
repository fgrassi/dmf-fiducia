<?php

namespace App\Filament\Livewire;

use App\Actions\Financeiro\BookingReceiptTimeReport;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** "Resumo geral" do Tempo Booking × Recebimento: operações e média de dias, no geral, em IMPO e em EXPO (os filtros vêm da página). */
class BookingReceiptTimeSummary extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    /** @var array<string, mixed> */
    public array $reportFilters = [];

    /** @param  array<string, mixed>  $reportFilters */
    public function mount(array $reportFilters = []): void
    {
        $this->reportFilters = $reportFilters;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Resumo geral')
            ->records(function (): array {
                $averages = app(BookingReceiptTimeReport::class)($this->reportFilters)['medias'];
                $key = ArrayRecord::getKeyName();

                return [
                    'geral' => [$key => 'geral', 'resumo' => 'Geral'] + $averages['geral'],
                    'impo' => [$key => 'impo', 'resumo' => 'IMPO'] + $averages['impo'],
                    'expo' => [$key => 'expo', 'resumo' => 'EXPO'] + $averages['expo'],
                ];
            })
            ->paginated(false)
            ->columns([
                TextColumn::make('resumo')
                    ->label(''),
                TextColumn::make('count')
                    ->label('Operações')
                    ->alignEnd(),
                TextColumn::make('media')
                    ->label('Média (dias)')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => $state === null ? '-' : number_format((float) $state, 1, ',', '.')),
            ]);
    }

    public function render(): View
    {
        return view('livewire.booking-receipt-time-table');
    }
}
