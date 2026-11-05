<?php

namespace App\Filament\Pages;

use App\Actions\Financeiro\ExportFasaProcessSheet;
use App\Actions\Financeiro\FasaProcessSheet as Sheet;
use App\Enums\Cobranca\ChargeStatus;
use App\Filament\Livewire\FasaSectionTable;
use App\Models\Embarque;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/** financeiro/planilhaFasa (legado), "Planilha Processos FASA": cobranças por cliente ou grupo, em três seções, com exportação para XLSX. */
class FasaProcessSheet extends Page
{
    /** Grupo de empresas que o legado abre selecionado (FAROS). */
    private const DEFAULT_GROUP = 1;

    protected static ?string $title = 'Planilha Processos FASA';

    protected static ?string $navigationLabel = 'Planilha Processos FASA';

    protected static ?string $slug = 'financeiro/relatorios/planilha-processos-fasa';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Relatórios';

    protected static ?int $navigationSort = 11;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Filtros do último "Filtrar": a planilha só muda quando o botão é usado. */
    public ?array $applied = null;

    /** security.yml do módulo financeiro (legado): credencial financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    public function mount(): void
    {
        $this->form->fill(['grupo_empresa_id' => self::DEFAULT_GROUP, 'venc_ini' => now()->subDays(30)->toDateString()]);
        $this->filter();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])
                    ->schema([
                        Select::make('grupo_empresa_id')
                            ->label('Grupo de Empresa')
                            ->options(fn () => DB::table('grupo_empresa')->orderBy('nome')->pluck('nome', 'grupo_empresa_id')->all()),
                        TextInput::make('cliente')
                            ->label('Cliente / Exportador / Importador'),
                        Select::make('impor_expor')
                            ->label('Tipo (IMPO/EXPO)')
                            ->options(['i' => 'IMPO', 'e' => 'EXPO']),
                        Select::make('tipo_embarque')
                            ->label('Modal')
                            ->options(array_intersect_key(Embarque::TYPES, array_flip(['m', 'a', 's']))),
                        DatePicker::make('venc_ini')
                            ->label('Vencimento de'),
                        DatePicker::make('venc_fim')
                            ->label('Vencimento até'),
                        DatePicker::make('baixa_ini')
                            ->label('Baixa de'),
                        DatePicker::make('baixa_fim')
                            ->label('Baixa até'),
                        Select::make('status')
                            ->label('Status')
                            ->options(collect(ChargeStatus::cases())->mapWithKeys(fn ($status) => [$status->value => $status->label()])->all()),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('filter')
                    ->footer([
                        Actions::make([
                            Action::make('filter')
                                ->label('Filtrar')
                                ->submit('filter'),
                        ]),
                    ]),
                ...array_map(fn (string $section) => Livewire::make(FasaSectionTable::class, fn () => ['section' => $section, 'sheetFilters' => $this->applied ?? []])
                    ->key($section.md5(json_encode($this->applied))), ['embarques', 'taxas', 'detention']),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Exportar XLSX')
                ->color('gray')
                ->action(fn () => app(ExportFasaProcessSheet::class)($this->sections())),
        ];
    }

    public function filter(): void
    {
        $this->applied = $this->form->getState();
    }

    /** @return array<string, array<string, mixed>> */
    private function sections(): array
    {
        return app(Sheet::class)($this->applied ?? []);
    }
}
