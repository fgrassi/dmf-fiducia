<?php

namespace App\Filament\Pages;

use App\Actions\Financeiro\ExportInvoiceCoverFileReport;
use App\Actions\Financeiro\InvoiceCoverFileReport;
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
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
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

    /** @var array<string, mixed>|null */
    public ?array $report = null;

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
                View::make('filament.pages.invoice-cover-file')
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

        return $report ? app(ExportInvoiceCoverFileReport::class)($report) : null;
    }

    public function clear(): void
    {
        $this->form->fill();
        $this->report = null;
    }

    /** @return array<string, mixed>|null null quando o período ou o arquivo são inválidos (a mensagem sai como aviso) */
    private function build(): ?array
    {
        try {
            return app(InvoiceCoverFileReport::class)($this->form->getState());
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();

            return null;
        }
    }
}
