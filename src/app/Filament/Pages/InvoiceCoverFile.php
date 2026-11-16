<?php

namespace App\Filament\Pages;

use App\Actions\Financeiro\ExportInvoiceCoverFileReport;
use App\Actions\Financeiro\InvoiceCoverFileReport;
use App\Filament\Livewire\ReportSectionTable;
use App\Models\CapaProcessoArquivo;
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
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * relatorioNfCapa (legado), "NF x arquivo de capa": as NFS-e emitidas no período contra o arquivo de capa escolhido (anteriores,
 * posteriores, sem arquivo e de outro arquivo do mesmo mês), com o custo em aberto das posteriores e o Excel. Fica no menu Relatórios.
 */
class InvoiceCoverFile extends Page
{
    protected static ?string $title = 'NF x arquivo de capa';

    protected static ?string $navigationLabel = 'NF x arquivo de capa';

    protected static ?string $slug = 'financeiro/relatorios/nf-x-arquivo-de-capa';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Relatórios';

    protected static ?int $navigationSort = 16;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Filtros do último "Enviar": o resultado fica nas tabelas filhas, fora do estado da página. */
    public ?array $applied = null;

    /** Muda a cada "Enviar", para o resultado ser refeito mesmo com os mesmos filtros. */
    public int $runs = 0;

    /** security.yml do módulo (legado): financial (admin entra em qualquer módulo). */
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
                Grid::make(['default' => 1, 'md' => 2])
                    ->schema([
                        DatePicker::make('data_ini')
                            ->label('Emissão inicial')
                            ->required(),
                        DatePicker::make('data_fim')
                            ->label('Emissão final')
                            ->required()
                            ->afterOrEqual('data_ini'),
                    ]),
                Select::make('capa_processo_arquivo_id')
                    ->label('Arquivo de capa')
                    ->required()
                    ->searchable()
                    ->options(fn () => CapaProcessoArquivo::query()
                        ->orderByDesc('capa_processo_arquivo_id')
                        ->get()
                        ->mapWithKeys(fn (CapaProcessoArquivo $file) => [$file->getKey() => filled($file->nome) ? $file->nome : sprintf('%02d/%d', $file->mes, $file->ano)])
                        ->all()),
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
                                ->label('Baixar Excel')
                                ->color('gray')
                                ->action(fn () => $this->excel()),
                            Action::make('clear')
                                ->label('Limpar')
                                ->color('gray')
                                ->action(fn () => $this->clear()),
                        ]),
                    ]),
                Group::make(fn () => $this->sections()),
            ]);
    }

    public function send(): void
    {
        $this->applied = $this->form->getState();
        $this->runs++;

        if (! $this->build()) {
            $this->applied = null;
        }
    }

    public function excel(): ?StreamedResponse
    {
        $report = $this->build();

        return $report ? app(ExportInvoiceCoverFileReport::class)($report) : null;
    }

    public function clear(): void
    {
        $this->form->fill();
        $this->applied = null;
    }

    /** @return array<string, mixed>|null null quando o período ou o arquivo são inválidos (a mensagem sai como aviso) */
    private function build(): ?array
    {
        try {
            return self::run($this->form->getState());
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();

            return null;
        }
    }

    /**
     * Resumo, as listas de notas e o total em aberto, sob o mesmo "Enviar". Montadas na hora de desenhar, porque o conteúdo da
     * página já pode ter sido montado antes de o "Enviar" chegar.
     *
     * @return list<Livewire>
     */
    private function sections(): array
    {
        if ($this->applied === null) {
            return [];
        }

        $report = self::run($this->applied);
        $sections = array_filter(['summary', 'anterior', 'posterior', 'nenhum', 'mesmo_mes', 'totals'], fn (string $section) => $section !== 'mesmo_mes' || $report['mesmo_mes'] !== []);

        return array_map(fn (string $section) => Livewire::make(ReportSectionTable::class, fn () => ['page' => static::class, 'section' => $section, 'filters' => $this->applied])
            ->key($section.$this->runs), array_values($sections));
    }

    /** @param array<string, mixed> $filters */
    public static function sectionTable(Table $table, string $section, array $filters): Table
    {
        $report = self::run($filters);
        $keyed = fn (array $rows) => collect($rows)->mapWithKeys(fn (array $row, int $index) => ["r{$index}" => $row])->all();
        $money = fn ($state) => number_format((float) $state, 2, ',', '.');

        if ($section === 'summary') {
            return $table
                ->heading('Resumo')
                ->records(fn () => ['resumo' => ['periodo' => $report['periodo'], 'arquivo' => $report['arquivo_nome']] + $report['resumo']])
                ->columns([
                    TextColumn::make('periodo')
                        ->label('Período'),
                    TextColumn::make('arquivo')
                        ->label('Arquivo de referência'),
                    ...array_map(fn (string $key, string $label) => TextColumn::make($key)
                        ->label($label)
                        ->alignCenter(), ['no_arquivo', 'anterior', 'posterior', 'nenhum', 'mesmo_mes'], ['No arquivo', 'Anterior', 'Posterior', 'Sem arquivo', 'Outro no mesmo mês']),
                ]);
        }

        if ($section === 'totals') {
            return $table
                ->heading('Totais')
                ->description('O custo em aberto soma as despesas das capas posteriores e desconta só o que já está em ordem de pagamento paga. O total não repete a mesma capa.')
                ->records(fn () => ['total' => ['label' => 'Total em aberto (sem repetir capa)', 'custo' => $report['resumo']['custo_em_aberto']]])
                ->columns([
                    TextColumn::make('label')
                        ->label('')
                        ->weight(FontWeight::SemiBold),
                    TextColumn::make('custo')
                        ->label('Custo em aberto')
                        ->alignEnd()
                        ->formatStateUsing($money),
                ]);
        }

        $titles = [
            'anterior' => 'NFS-e com capa em arquivo anterior',
            'posterior' => 'NFS-e com capa em arquivo posterior',
            'nenhum' => 'NFS-e sem capa arquivada',
            'mesmo_mes' => 'NFS-e em outro arquivo do mesmo mês',
        ];

        return $table
            ->heading($titles[$section])
            ->records(fn () => $keyed($report[$section]))
            ->emptyStateHeading('Nenhuma nota neste grupo.')
            ->columns([
                TextColumn::make('data_nf')
                    ->label('Data da NF'),
                TextColumn::make('numero_nf')
                    ->label('Número da NF'),
                TextColumn::make('valor_nf')
                    ->label('Valor da NF')
                    ->alignEnd()
                    ->formatStateUsing($money),
                TextColumn::make('booking')
                    ->label('Reserva'),
                TextColumn::make('arquivo_nome')
                    ->label('Arquivo da capa')
                    ->visible($section !== 'nenhum'),
                TextColumn::make('custo_em_aberto')
                    ->label('Custo em aberto')
                    ->alignEnd()
                    ->formatStateUsing($money)
                    ->visible($section === 'posterior'),
            ]);
    }

    /**
     * Relatório dos filtros, o mesmo para as tabelas e o Excel na requisição.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     *
     * @throws DomainException
     */
    private static function run(array $filters): array
    {
        return Cache::driver('array')->rememberForever('invoice-cover-file:'.md5(json_encode($filters)), fn () => app(InvoiceCoverFileReport::class)($filters));
    }
}
