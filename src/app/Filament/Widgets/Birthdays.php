<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Quadro de aniversários: colaboradores ativos da DMF (pessoas 2 e 3198, como no legado) com o ano todo em ordem de mês e dia (os que já passaram em cinza, o mês atual em destaque). */
class Birthdays extends TableWidget
{
    /** Pessoas (empresas) cujos usuários entram no quadro, iguais ao dashboard/aniversarios do tracking. */
    private const COMPANIES = [2, 3198];

    protected static ?int $sort = 60;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Aniversários')
            ->query(fn () => User::query()
                ->select('usuario.*')
                ->addSelect(['has_seller' => DB::table('cmn_vendedor_usuario')->selectRaw('1')->whereColumn('ref_usuario', 'usuario.usuario_id')->whereNull('deleted_at')->limit(1)])
                ->where('status', '>', 0)
                ->whereNotNull('aniversario')
                ->whereIn('usuario_id', DB::table('pessoa_usuario')->select('usuario_id')->whereIn('pessoa_id', self::COMPANIES)))
            // Ordem padrão (e desempate): mês e dia; o ano de nascimento não conta. Fica fora da query para a coluna clicada vir primeiro.
            ->defaultSort(fn (Builder $query) => $query->orderByRaw('SUBSTR(aniversario, 6)')->orderBy('nome'))
            ->columns([
                TextColumn::make('aniversario')
                    ->label('Data')
                    ->date('d/m')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw("SUBSTR(aniversario, 6) {$direction}"))
                    ->weight(fn (User $record) => $record->aniversario?->month === today()->month ? 'bold' : null)
                    ->color(fn (User $record) => match (true) {
                        $this->isToday($record) => 'danger',
                        $record->aniversario?->month < today()->month => 'gray',
                        default => null,
                    }),
                TextColumn::make('nome')
                    ->label('Colaborador')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (string $state) => mb_convert_case($state, MB_CASE_TITLE)),
                // Vendedor ativo mostra o celular; os demais, o ramal (sem o prefixo da central).
                TextColumn::make('contact')
                    ->label('Ramal/Celular')
                    ->state(fn (User $record) => $record->has_seller
                        ? str_replace('+55', '', (string) $record->celular)
                        : str_replace(['(51) 3717-', '+55'], '', (string) $record->fone))
                    ->placeholder('—'),
                IconColumn::make('today')
                    ->label('')
                    ->state(fn (User $record) => $this->isToday($record))
                    ->icon(fn (bool $state) => $state ? Heroicon::OutlinedCake : null)
                    ->color('danger'),
            ])
            ->paginated(false)
            ->emptyStateHeading('Nenhum aniversário cadastrado');
    }

    private function isToday(User $user): bool
    {
        return $user->aniversario?->isBirthday(today()) ?? false;
    }
}
