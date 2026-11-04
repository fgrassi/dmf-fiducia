<?php

namespace App\Filament\Pages;

use App\Actions\Expenses\ChargedPaidReport;
use App\Actions\Expenses\ExportChargedPaidReport;
use App\Models\CapaProcessoArquivo;
use App\Models\FinExpenses;
use App\Models\Pessoa;
use App\Models\RegraDespesaCobranca;
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
 * relatorioDespesasCobradasPagas (legado): valores cobrados (IC) × pagos (ordens de pagamento) por despesa e reserva, num
 * período (por cobrança, pagamento, ETD ou ETA) e/ou por arquivo de capa, com Excel. Fica no menu Despesas.
 */
class ChargedPaidExpenses extends Page
{
    protected static ?string $title = 'Relatório de Despesas Cobradas/Pagas';

    protected static ?string $navigationLabel = 'Despesas Cobradas/Pagas';

    protected static ?string $slug = 'financeiro/despesas/cobradas-pagas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Despesas';

    protected static ?int $navigationSort = 3;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $report = null;

    /** security.yml do módulo (legado): credencial financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    public function mount(): void
    {
        $this->form->fill(['tipo_data' => 'cobranca']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Grid::make(['default' => 1, 'md' => 2])
                    ->schema([
                        DatePicker::make('data_ini')
                            ->label('Data inicial'),
                        DatePicker::make('data_fim')
                            ->label('Data final'),
                        Select::make('tipo_data')
                            ->label('Filtrar por')
                            ->options(ChargedPaidReport::DATE_AXES)
                            ->selectablePlaceholder(false),
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
                        Select::make('despesa_ids')
                            ->label('Despesas')
                            ->multiple()
                            ->searchable()
                            ->options(fn () => FinExpenses::query()->orderBy('name')->pluck('name', 'id'))
                            ->columnSpanFull(),
                        Select::make('regra_ids')
                            ->label('Grupo / regra de despesa')
                            ->multiple()
                            ->searchable()
                            ->options(fn () => RegraDespesaCobranca::query()->orderBy('nome')->pluck('nome', 'regra_despesa_cobranca_id'))
                            ->columnSpanFull(),
                        Select::make('arquivo_id')
                            ->label('Arquivo de capa')
                            ->searchable()
                            ->options(fn () => CapaProcessoArquivo::query()->orderByDesc('capa_processo_arquivo_id')->pluck('nome', 'capa_processo_arquivo_id')),
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
                            Action::make('excel')
                                ->label('Excel')
                                ->color('gray')
                                ->action(fn () => $this->excel()),
                            Action::make('clear')
                                ->label('Limpar')
                                ->color('gray')
                                ->action(fn () => $this->clear()),
                        ]),
                    ]),
                View::make('filament.pages.charged-paid-expenses')
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

        return $report ? app(ExportChargedPaidReport::class)($report) : null;
    }

    public function clear(): void
    {
        $this->form->fill(['tipo_data' => 'cobranca']);
        $this->report = null;
    }

    /** @return array<string, mixed>|null null quando o filtro é inválido (a mensagem sai como aviso) */
    private function build(): ?array
    {
        $state = $this->form->getState();

        try {
            return app(ChargedPaidReport::class)([
                'data_ini' => $state['data_ini'] ?? null,
                'data_fim' => $state['data_fim'] ?? null,
                'tipo_data' => $state['tipo_data'] ?? null,
                'despesa_ids' => $state['despesa_ids'] ?? [],
                'regra_ids' => $state['regra_ids'] ?? [],
                'cliente_id' => $state['cliente_id'] ?? null,
                'arquivo_id' => $state['arquivo_id'] ?? null,
            ]);
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();

            return null;
        }
    }
}
