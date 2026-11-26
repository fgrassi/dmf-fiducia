<?php

namespace App\Filament\Livewire;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/** Ícone ao lado da busca global que abre os aniversários: colaboradores ativos da DMF (pessoas 2 e 3198, como no legado) com o ano todo em ordem de mês e dia (os que já passaram em cinza, o mês atual em destaque). */
class Birthdays extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** Pessoas (empresas) cujos usuários entram no quadro, iguais ao dashboard/aniversarios do tracking. */
    private const COMPANIES = [2, 3198];

    /** Só quem também é colaborador (vinculado às mesmas empresas) vê nomes, aniversários e telefones dos colegas. */
    public static function canView(): bool
    {
        $id = auth()->id();

        return $id !== null && DB::table('pessoa_usuario')->where('usuario_id', $id)->whereIn('pessoa_id', self::COMPANIES)->exists();
    }

    public function birthdaysAction(): Action
    {
        return Action::make('birthdays')
            ->label('Aniversários')
            ->icon(Heroicon::OutlinedCake)
            ->iconButton()
            // Vermelho quando alguém faz aniversário hoje.
            ->color(fn () => $this->people()->contains(fn (User $user) => $this->isToday($user)) ? 'danger' : 'gray')
            ->visible(fn () => self::canView())
            ->slideOver()
            ->modalHeading('Aniversários')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->schema([
                RepeatableEntry::make('people')
                    ->hiddenLabel()
                    ->state(fn () => $this->people())
                    ->placeholder('Nenhum aniversário cadastrado')
                    ->table([
                        TableColumn::make('Data'),
                        TableColumn::make('Colaborador'),
                        TableColumn::make('Ramal/Celular'),
                    ])
                    ->schema([
                        TextEntry::make('aniversario')
                            ->date('d/m')
                            ->icon(fn (User $record) => $this->isToday($record) ? Heroicon::OutlinedCake : null)
                            ->weight(fn (User $record) => $record->aniversario->month === today()->month ? FontWeight::Bold : null)
                            ->color(fn (User $record) => match (true) {
                                $this->isToday($record) => 'danger',
                                $record->aniversario->month < today()->month => 'gray',
                                default => null,
                            }),
                        TextEntry::make('nome')
                            ->formatStateUsing(fn (string $state) => mb_convert_case($state, MB_CASE_TITLE)),
                        // Vendedor ativo mostra o celular; os demais, o ramal (sem o prefixo da central).
                        TextEntry::make('contact')
                            ->state(fn (User $record) => $record->has_seller
                                ? str_replace('+55', '', (string) $record->celular)
                                : str_replace(['(51) 3717-', '+55'], '', (string) $record->fone))
                            ->placeholder('—'),
                    ]),
            ]);
    }

    public function render(): View
    {
        return view('filament.birthdays');
    }

    /** @return Collection<int, User> */
    private function people(): Collection
    {
        return User::query()
            ->select('usuario.*')
            ->addSelect(['has_seller' => DB::table('cmn_vendedor_usuario')->selectRaw('1')->whereColumn('ref_usuario', 'usuario.usuario_id')->whereNull('deleted_at')->limit(1)])
            ->where('status', '>', 0)
            ->whereNotNull('aniversario')
            ->whereIn('usuario_id', DB::table('pessoa_usuario')->select('usuario_id')->whereIn('pessoa_id', self::COMPANIES))
            // Mês e dia; o ano de nascimento não conta.
            ->orderByRaw('SUBSTR(aniversario, 6)')
            ->orderBy('nome')
            ->get();
    }

    private function isToday(User $user): bool
    {
        return $user->aniversario->isBirthday(today());
    }
}
