<?php

namespace App\Filament\Pages;

use App\Actions\Financeiro\SiscoservPaymentsReport;
use App\Models\Pessoa;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * pagamentoSiscoserv/index (legado), "Pagamentos Siscoserv" (menu 6004), no menu Consultas: reservas marítimas de importação
 * com cobrança paga no período, por cliente. O legado não tinha security.yml neste módulo: segue a credencial financial.
 */
class SiscoservPayments extends Page
{
    protected static ?string $title = 'Pagamentos Siscoserv';

    protected static ?string $navigationLabel = 'Pagamentos Siscoserv';

    protected static ?string $slug = 'financeiro/pagamentos-siscoserv';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Consultas';

    protected static ?int $navigationSort = 40;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Filtros do último "Enviar": a tabela só muda quando o botão é usado. */
    public ?array $applied = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Grid::make(['default' => 1, 'md' => 3])
                    ->schema([
                        DatePicker::make('data_ini')
                            ->label('Data inicial'),
                        DatePicker::make('data_fim')
                            ->label('Data final')
                            ->afterOrEqual('data_ini'),
                        Select::make('cliente_id')
                            ->label('Cliente')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => Pessoa::query()
                                ->where(fn ($query) => $query->where('nome', 'like', "%{$search}%")->orWhere('cnpj', 'like', "%{$search}%"))
                                ->orderBy('nome')
                                ->limit(30)
                                ->get()
                                ->mapWithKeys(fn (Pessoa $person) => [$person->getKey() => $person->nome.(filled($person->cnpj) ? " ({$person->cnpj})" : '')]))
                            ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome')),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('send')
                    ->footer([
                        Actions::make([
                            Action::make('send')
                                ->label('Enviar')
                                ->submit('send'),
                            Action::make('clear')
                                ->label('Limpar')
                                ->color('gray')
                                ->action(fn () => $this->clear()),
                        ]),
                    ]),
                View::make('filament.pages.siscoserv-payments')
                    ->viewData(fn () => ['rows' => app(SiscoservPaymentsReport::class)($this->applied ?? [])]),
            ]);
    }

    public function send(): void
    {
        $this->applied = $this->form->getState();
    }

    public function clear(): void
    {
        $this->form->fill();
        $this->applied = null;
    }
}
