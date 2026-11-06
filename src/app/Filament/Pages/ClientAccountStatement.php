<?php

namespace App\Filament\Pages;

use App\Actions\Financeiro\ClientAccountStatement as ClientAccountStatementReport;
use App\Actions\Financeiro\ExportClientAccountStatement;
use App\Models\Pessoa;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * relatorioStatementConta (legado), "Extrato de Conta - Cliente": itens em aberto do cliente na tela e o Excel completo
 * (Info, Statement, Invoices e Payments). Fica no menu Relatórios.
 */
class ClientAccountStatement extends Page
{
    protected static ?string $title = 'Extrato de Conta - Cliente';

    protected static ?string $navigationLabel = 'Extrato de Conta - Cliente';

    protected static ?string $slug = 'financeiro/relatorios/extrato-conta-cliente';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Relatórios';

    protected static ?int $navigationSort = 15;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $report = null;

    /** security.yml do módulo (legado): financial, director, manager ou supervisor (admin entra em qualquer módulo). */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential(['financial', 'director', 'manager', 'supervisor']);
    }

    public function mount(): void
    {
        $this->form->fill($this->defaults());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('cliente_id')
                    ->label('Cliente')
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Pessoa::query()
                        ->where(fn ($query) => $query->where('nome', 'like', "%{$search}%")->orWhere('cnpj', 'like', "%{$search}%"))
                        ->orderBy('nome')
                        ->limit(30)
                        ->get()
                        ->mapWithKeys(fn (Pessoa $person) => [$person->getKey() => $person->nome.(filled($person->cnpj) ? " ({$person->cnpj})" : '')]))
                    ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome')),
                Grid::make(['default' => 1, 'md' => 2])
                    ->schema([
                        DatePicker::make('data_ini')
                            ->label('Emissão de')
                            ->required(),
                        DatePicker::make('data_fim')
                            ->label('Emissão até'),
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
                                ->label('Gerar')
                                ->submit('send'),
                            Action::make('excel')
                                ->label('Baixar Excel')
                                ->color('gray')
                                ->action(fn () => $this->excel()),
                            Action::make('clear')
                                ->label('Limpar')
                                ->color('gray')
                                ->action(fn () => $this->clear()),
                        ]),
                    ]),
                View::make('filament.pages.client-account-statement')
                    ->viewData(fn () => ['report' => $this->report]),
            ]);
    }

    public function send(): void
    {
        $this->report = $this->build();
    }

    public function excel(): ?StreamedResponse
    {
        $report = $this->build();

        return $report ? app(ExportClientAccountStatement::class)($report) : null;
    }

    public function clear(): void
    {
        $this->form->fill($this->defaults());
        $this->report = null;
    }

    /** @return array<string, mixed>|null null quando falta o cliente ou a data inicial (a mensagem sai como aviso) */
    private function build(): ?array
    {
        $state = $this->form->getState();

        try {
            return app(ClientAccountStatementReport::class)($state);
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();

            return null;
        }
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return ['data_fim' => today()->toDateString()];
    }
}
