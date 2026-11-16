<?php

namespace App\Filament\Support;

use App\Enums\ContainerTime;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Colunas, consulta e abas do controle de demurrage/detention (listSuccess dos módulos demurrage e detention, legado). */
class ContainerTimeTable
{
    /** Registros com a reserva e os nomes que a listagem mostra, por join (a busca por coluna filtra neles). */
    public static function query(ContainerTime $type): Builder
    {
        $table = (new ($type->model()))->getTable();

        return $type->model()::query()
            ->with('embarque')
            ->join('embarque as e', 'e.embarque_id', '=', "{$table}.ref_embarque")
            ->leftJoin('armador as ar', 'ar.armador_id', '=', 'e.armador_id')
            ->leftJoin('porto as po', 'po.porto_id', '=', "e.{$type->portColumn()}")
            ->leftJoin('navio as nv', 'nv.navio_id', '=', $type === ContainerTime::Demurrage ? 'e.navio_id_destino' : 'e.navio_id')
            ->leftJoin('pessoa as pe', 'pe.pessoa_id', '=', 'e.cliente_id')
            ->leftJoin('tipo_container as tc', 'tc.tipo_container_id', '=', "{$table}.ref_tipo_container")
            ->select("{$table}.*", 'e.booking', 'e.saida_efetiva', 'ar.nome as armador_nome', 'po.nome as porto_nome', 'nv.nome as navio_nome', 'pe.nome as cliente_nome', 'pe.cnpj as cliente_cnpj', 'tc.nome as tipo_nome');
    }

    /** @return array<int, TextColumn> */
    public static function columns(ContainerTime $type): array
    {
        $demurrage = $type === ContainerTime::Demurrage;
        $search = fn (string $column) => fn (Builder $query, string $search) => $query->where($column, 'like', "%{$search}%");

        return array_values(array_filter([
            TextColumn::make('armador_nome')
                ->label('Armador')
                ->searchable(isIndividual: true, query: $search('ar.nome')),
            TextColumn::make('porto_nome')
                ->label($demurrage ? 'Porto' : 'Porto origem')
                ->searchable(isIndividual: true, query: $search('po.nome')),
            TextColumn::make('navio_nome')
                ->label('Navio'),
            TextColumn::make('cliente_nome')
                ->label('Cliente')
                ->formatStateUsing(fn ($state, Model $record) => $state ? "{$state} ({$record->cliente_cnpj})" : '')
                ->searchable(isIndividual: true, query: $search('pe.nome')),
            TextColumn::make('booking')
                ->label('Booking')
                ->searchable(isIndividual: true, query: $search('e.booking')),
            TextColumn::make('container')
                ->label('Container')
                ->searchable(isIndividual: true, query: $search((new ($type->model()))->getTable().'.container')),
            $demurrage
                ? TextColumn::make('tipo_nome')->label('Tipo Container')
                : TextColumn::make('saida_efetiva')->label('Saída efetiva')->date('d/m/Y'),
            TextColumn::make('free_time')
                ->label('Free Time')
                ->state(fn (Model $record) => $record->freeTimeDays() ?: null)
                ->placeholder('-'),
            TextColumn::make('data_retirada')
                ->label($demurrage ? 'Data descarga' : 'Data retirada')
                ->date('d/m/Y'),
            TextColumn::make('data_devolucao')
                ->label('Data devolução')
                ->date('d/m/Y'),
            TextColumn::make('operation_days')
                ->label('Tempo operação')
                ->state(fn (Model $record) => $record->operationDays())
                ->placeholder('-'),
            TextColumn::make('overdue_days')
                ->label($type->label())
                ->state(fn (Model $record) => $record->overdueDays())
                ->placeholder('-')
                ->color(fn (Model $record) => $record->isCharged() ? 'danger' : null),
            $demurrage ? self::fileColumn() : null,
        ]));
    }

    /** Anexo do demurrage (fatura do armador). */
    public static function fileColumn(): TextColumn
    {
        return TextColumn::make('ref_file')
            ->label('Arquivo')
            ->formatStateUsing(fn ($state) => $state ? 'Abrir' : '')
            ->url(fn (Model $record) => $record->ref_file ? Storage::disk('tracking_uploads')->url('demurrage/'.rawurlencode($record->ref_file)) : null, shouldOpenInNewTab: true);
    }

    /**
     * Abas "Exibir" do legado; o detention tem ainda os que têm cobrança e os reservas sem free time.
     *
     * @return array<string, Tab>
     */
    public static function tabs(ContainerTime $type): array
    {
        $table = (new ($type->model()))->getTable();
        $open = fn (Builder $query) => $query->where(fn ($q) => $q->whereNull("{$table}.data_retirada")->orWhereNull("{$table}.data_devolucao"));
        $days = DB::getDriverName() === 'sqlite'
            ? "(julianday({$table}.data_devolucao) - julianday({$table}.data_retirada))"
            : "datediff({$table}.data_devolucao, {$table}.data_retirada)";

        return [
            'abertos' => Tab::make('Abertos')->modifyQueryUsing($open),
            'fechados' => Tab::make('Fechados')->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull("{$table}.data_retirada")->whereNotNull("{$table}.data_devolucao")),
            'todos' => Tab::make('Todos'),
            ...($type === ContainerTime::Detention ? [
                'detention' => Tab::make('Com detention')->modifyQueryUsing(fn (Builder $query) => $query->whereRaw("({$days} + 1 - coalesce(e.free_time_detention, 0)) > 0")),
                'sem_free_time' => Tab::make('Sem free time')->modifyQueryUsing(fn (Builder $query) => $query->whereNull('e.free_time_detention')),
            ] : []),
        ];
    }
}
