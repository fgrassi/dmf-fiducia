<?php

namespace App\Filament\Pages;

use App\Actions\Financeiro\CertificateCorrectionsReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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
 * relatorioDespesasReceitas (legado), "Arrecadação Certificados e Correções": despesa × receita de certificados e correções
 * por reserva e data da despesa (ETD no EXPO, ETA no IMPO). Fica no menu Relatórios.
 */
class CertificateCorrections extends Page
{
    protected static ?string $title = 'Arrecadação Certificados e Correções';

    protected static ?string $navigationLabel = 'Arrecadação Certificados e Correções';

    protected static ?string $slug = 'financeiro/relatorios/arrecadacao-certificados-correcoes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Relatórios';

    protected static ?int $navigationSort = 17;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $report = null;

    /** O módulo não tinha security.yml; segue a credencial dos demais relatórios financeiros. */
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
                            ->label('Data inicial'),
                        DatePicker::make('data_fim')
                            ->label('Data final'),
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
                View::make('filament.pages.certificate-corrections')
                    ->viewData(fn () => ['report' => $this->report]),
            ]);
    }

    public function send(): void
    {
        $this->report = app(CertificateCorrectionsReport::class)($this->form->getState());
    }

    public function clear(): void
    {
        $this->form->fill();
        $this->report = null;
    }
}
