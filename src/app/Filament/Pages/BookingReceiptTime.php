<?php

namespace App\Filament\Pages;

use App\Actions\Financeiro\BookingReceiptTimeReport;
use App\Actions\Financeiro\ExportBookingReceiptTime;
use App\Filament\Livewire\BookingReceiptTimeList;
use App\Filament\Livewire\BookingReceiptTimeSummary;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * relatorioTempoRecebimento (legado), "Tempo Booking × Recebimento": dias entre a criação da reserva e a primeira baixa de
 * cobrança, por reserva, cliente e operação. Duas tabelas (a lista e o resumo geral) e o Excel. Fica no menu Relatórios.
 */
class BookingReceiptTime extends Page
{
    protected static ?string $title = 'Tempo Booking × Recebimento';

    protected static ?string $navigationLabel = 'Tempo Booking × Recebimento';

    protected static ?string $slug = 'financeiro/relatorios/tempo-booking-recebimento';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Relatórios';

    protected static ?int $navigationSort = 14;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Filtros do último "Enviar": as tabelas só mudam quando o botão é usado. */
    public ?array $applied = null;

    /** security.yml do módulo (legado): (manager e financial) ou director. */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->hasLegacyCredential('director') || ($user?->hasLegacyCredential('financial') && $user?->hasLegacyCredential('manager')));
    }

    public function mount(): void
    {
        $this->form->fill($this->defaults());
        $this->applied = $this->defaults();
    }

    public function form(Schema $schema): Schema
    {
        $years = range(2013, now()->year + 1);

        return $schema
            ->statePath('data')
            ->components([
                Grid::make(['default' => 2, 'md' => 4])
                    ->schema([
                        Select::make('mes_ini')
                            ->label('Mês início')
                            ->options(BookingReceiptTimeReport::MONTHS)
                            ->selectablePlaceholder(false),
                        Select::make('ano_ini')
                            ->label('Ano início')
                            ->options(array_combine($years, $years))
                            ->selectablePlaceholder(false),
                        Select::make('mes_fim')
                            ->label('Mês fim')
                            ->options(BookingReceiptTimeReport::MONTHS)
                            ->selectablePlaceholder(false),
                        Select::make('ano_fim')
                            ->label('Ano fim')
                            ->options(array_combine($years, $years))
                            ->selectablePlaceholder(false),
                    ]),
                Grid::make(['default' => 1, 'md' => 4])
                    ->schema([
                        Select::make('impor_expor')
                            ->label('Tipo (IMPO/EXPO)')
                            ->options(BookingReceiptTimeReport::TYPES)
                            ->placeholder('Todos'),
                        Select::make('agrupar_por')
                            ->label('Agrupar por')
                            ->options(BookingReceiptTimeReport::GROUPS)
                            ->placeholder('Sem agrupamento'),
                        Select::make('ordenar_por')
                            ->label('Ordenar por')
                            ->options(BookingReceiptTimeReport::SORTS)
                            ->selectablePlaceholder(false),
                        Select::make('ordenar_dir')
                            ->label('Ordem')
                            ->options(['asc' => 'Crescente (ASC)', 'desc' => 'Decrescente (DESC)'])
                            ->selectablePlaceholder(false),
                    ]),
                Grid::make(['default' => 1, 'md' => 3])
                    ->schema([
                        Checkbox::make('mostrar_lista')
                            ->label('Mostrar lista'),
                        Checkbox::make('com_prazo_negociado')
                            ->label('Com prazo negociado'),
                        Checkbox::make('com_boleto')
                            ->label('Com boleto'),
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
                                ->label('Exportar Excel')
                                ->color('gray')
                                ->action(fn () => $this->excel()),
                            Action::make('clear')
                                ->label('Limpar')
                                ->color('gray')
                                ->action(fn () => $this->clear()),
                        ]),
                    ]),
                Livewire::make(BookingReceiptTimeList::class, fn () => ['reportFilters' => $this->applied])
                    ->key('list'.md5(json_encode($this->applied))),
                Livewire::make(BookingReceiptTimeSummary::class, fn () => ['reportFilters' => $this->applied])
                    ->key('summary'.md5(json_encode($this->applied))),
            ]);
    }

    public function send(): void
    {
        $this->applied = $this->validated() ?? $this->applied;
    }

    public function excel(): ?StreamedResponse
    {
        $filters = $this->validated();

        return $filters ? app(ExportBookingReceiptTime::class)(app(BookingReceiptTimeReport::class)($filters)) : null;
    }

    public function clear(): void
    {
        $this->form->fill($this->defaults());
        $this->applied = $this->defaults();
    }

    /** @return array<string, mixed>|null os filtros do formulário; null quando o período é inválido (a mensagem sai como aviso) */
    private function validated(): ?array
    {
        $filters = $this->form->getState();

        try {
            app(BookingReceiptTimeReport::class)->period($filters);
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();

            return null;
        }

        return $filters;
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'mes_ini' => now()->month, 'ano_ini' => now()->year,
            'mes_fim' => now()->month, 'ano_fim' => now()->year,
            'ordenar_por' => 'cliente', 'ordenar_dir' => 'asc',
            'mostrar_lista' => true,
        ];
    }
}
