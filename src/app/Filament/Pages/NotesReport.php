<?php

namespace App\Filament\Pages;

use App\Actions\Financeiro\ExportNotesReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/** financeiro/index (legado), "Relatório Notas" (Notas FAROS): Excel de Notas de Débito pagas no mês, do Grupo Faros ou da JR. */
class NotesReport extends Page
{
    protected static ?string $title = 'Relatório Notas';

    protected static ?string $navigationLabel = 'Relatório Notas';

    protected static ?string $slug = 'financeiro/relatorios/relatorio-notas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Relatórios';

    protected static ?int $navigationSort = 19;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** security.yml do módulo financeiro (legado): credencial financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    public function mount(): void
    {
        $this->form->fill(['month' => (int) now()->format('n'), 'year' => (int) now()->format('Y')]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Grid::make(['default' => 1, 'md' => 2])
                    ->schema([
                        Select::make('month')
                            ->label('Mês')
                            ->options(array_combine(range(1, 12), ['JANEIRO', 'FEVEREIRO', 'MARCO', 'ABRIL', 'MAIO', 'JUNHO', 'JULHO', 'AGOSTO', 'SETEMBRO', 'OUTUBRO', 'NOVEMBRO', 'DEZEMBRO']))
                            ->required(),
                        Select::make('year')
                            ->label('Ano')
                            ->options(array_combine($years = range(2013, (int) now()->format('Y') + 1), $years))
                            ->required(),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form'),
                View::make('filament.pages.notes-report')
                    ->viewData(fn () => ['faros' => DB::table('grupo_empresa_membro as gem')
                        ->join('pessoa as p', 'p.pessoa_id', '=', 'gem.pessoa_id')
                        ->where('gem.grupo_empresa_id', 1)->orderBy('p.nome')->pluck('p.nome')]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('faros')
                ->label('Relatório Grupo Faros')
                ->action(fn () => $this->export(ExportNotesReport::FAROS)),
            Action::make('jr')
                ->label('Relatório JR')
                ->action(fn () => $this->export(ExportNotesReport::JR)),
        ];
    }

    private function export(int $report)
    {
        $data = $this->form->getState();

        return app(ExportNotesReport::class)($report, (int) $data['month'], (int) $data['year'])
            ?? Notification::make()->title('Não há recibos para o período selecionado!')->warning()->send();
    }
}
