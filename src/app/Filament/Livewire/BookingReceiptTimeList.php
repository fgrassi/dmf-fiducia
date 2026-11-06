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
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Component;

/**
 * Lista do Tempo Booking × Recebimento (os filtros vêm da página): uma linha por reserva, na ordem dos grupos quando
 * agrupa, ou uma linha por grupo quando agrupa sem listar as reservas.
 */
class BookingReceiptTimeList extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    /** @var array<string, mixed> */
    public array $reportFilters = [];

    /** @var array<string, mixed>|null */
    private ?array $report = null;

    /** @param  array<string, mixed>  $reportFilters */
    public function mount(array $reportFilters = []): void
    {
        $this->reportFilters = $reportFilters;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(fn () => $this->summaryOnly() ? 'Grupos' : 'Reservas')
            ->records(function (int $page, int|string $recordsPerPage): LengthAwarePaginator {
                $perPage = (int) $recordsPerPage;
                $records = $this->records();

                return new LengthAwarePaginator(array_slice($records, ($page - 1) * $perPage, $perPage), count($records), $perPage, $page);
            })
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Nenhum registro encontrado para o período informado')
            ->columns([
                TextColumn::make('grupo')
                    ->label('Grupo')
                    ->description(fn (array $record) => $record['grupo_resumo'] ?? null)
                    ->visible(fn () => $this->report()['agrupar_por'] !== ''),
                TextColumn::make('operacoes')
                    ->label('Operações')
                    ->alignEnd()
                    ->visible(fn () => $this->summaryOnly()),
                TextColumn::make('media')
                    ->label('Média (dias)')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => $state === null ? '-' : number_format((float) $state, 1, ',', '.'))
                    ->visible(fn () => $this->summaryOnly()),
                TextColumn::make('booking')
                    ->label('Booking')
                    ->visible(fn () => ! $this->summaryOnly()),
                TextColumn::make('cliente_nome')
                    ->label('Cliente')
                    ->visible(fn () => ! $this->summaryOnly()),
                TextColumn::make('impor_expor')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($state) => BookingReceiptTimeReport::TYPES[$state] ?? $state)
                    ->visible(fn () => ! $this->summaryOnly()),
                TextColumn::make('prazo_imp')
                    ->label('Prazo IMPO')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => BookingReceiptTimeReport::term($state))
                    ->visible(fn () => ! $this->summaryOnly()),
                TextColumn::make('boleto_imp')
                    ->label('Boleto IMPO')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => BookingReceiptTimeReport::boleto($state))
                    ->visible(fn () => ! $this->summaryOnly()),
                TextColumn::make('prazo_exp')
                    ->label('Prazo EXPO')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => BookingReceiptTimeReport::term($state))
                    ->visible(fn () => ! $this->summaryOnly()),
                TextColumn::make('boleto_exp')
                    ->label('Boleto EXPO')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => BookingReceiptTimeReport::boleto($state))
                    ->visible(fn () => ! $this->summaryOnly()),
                TextColumn::make('data_topo')
                    ->label('Data criação')
                    ->date('d/m/Y')
                    ->visible(fn () => ! $this->summaryOnly()),
                TextColumn::make('primeira_baixa')
                    ->label('Data 1ª baixa')
                    ->date('d/m/Y')
                    ->visible(fn () => ! $this->summaryOnly()),
                TextColumn::make('dias')
                    ->label('Dias')
                    ->alignEnd()
                    ->visible(fn () => ! $this->summaryOnly()),
            ]);
    }

    public function render(): View
    {
        return view('livewire.booking-receipt-time-table');
    }

    /** Agrupado e sem lista: só uma linha por grupo (Grupo, Operações, Média). */
    private function summaryOnly(): bool
    {
        return $this->report()['agrupar_por'] !== '' && ! $this->report()['mostrar_lista'];
    }

    /** @return list<array<string, mixed>> */
    private function records(): array
    {
        $report = $this->report();
        $key = ArrayRecord::getKeyName();

        if ($this->summaryOnly()) {
            return array_map(fn (array $group, int $i) => [$key => "g{$i}", 'grupo' => $group['label'], 'operacoes' => $group['count'], 'media' => $group['media']], $report['grupos'], array_keys($report['grupos']));
        }

        if ($report['agrupar_por'] === '') {
            return array_map(fn (array $row) => $row + [$key => (string) $row['embarque_id']], $report['rows']);
        }

        $records = [];

        foreach ($report['grupos'] as $group) {
            $summary = $group['count'].' ops — média '.($group['media'] === null ? '-' : number_format($group['media'], 1, ',', '.')).' dias';

            foreach ($group['rows'] as $row) {
                $records[] = $row + [$key => $group['label'].'|'.$row['embarque_id'], 'grupo' => $group['label'], 'grupo_resumo' => $summary];
            }
        }

        return $records;
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return $this->report ??= app(BookingReceiptTimeReport::class)($this->reportFilters);
    }
}
