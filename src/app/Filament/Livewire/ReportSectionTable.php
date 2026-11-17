<?php

namespace App\Filament\Livewire;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Uma seção (tabela) de um relatório com várias listas: a página monta uma por seção, com os filtros já aplicados, e é ela
 * quem define as colunas, em `sectionTable()`. Sem paginação, a menos que a seção peça com `->paginated()`.
 */
class ReportSectionTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    /** @var class-string */
    #[Locked]
    public string $page;

    #[Locked]
    public string $section;

    /** @var array<string, mixed> */
    #[Locked]
    public array $filters = [];

    /**
     * @param  class-string  $page  página com `sectionTable(Table, string, array): Table`
     * @param  array<string, mixed>  $filters
     */
    public function mount(string $page, string $section, array $filters = []): void
    {
        abort_unless(method_exists($page, 'sectionTable'), 404);

        $this->page = $page;
        $this->section = $section;
        $this->filters = $filters;
    }

    public function table(Table $table): Table
    {
        return $this->page::sectionTable($table->paginated(false), $this->section, $this->filters)
            ->extraAttributes(['class' => 'sas-ta-compact'], merge: true);
    }

    public function render(): View
    {
        return view('livewire.report-section-table');
    }
}
