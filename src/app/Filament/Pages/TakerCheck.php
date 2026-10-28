<?php

namespace App\Filament\Pages;

use App\Actions\Cobranca\Nfse\PendingTakers;
use App\Actions\Cobranca\Nfse\UpdateTakers;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * financeiro/verificaTomador + atualizaTomador (legado): tomadores dos BLs do período com cadastro incompleto para a NFS-e,
 * editáveis na própria tela. "Atualizar cadastro" grava na pessoa (cria a que não existe).
 */
class TakerCheck extends Page
{
    protected static ?string $title = 'Dados dos Tomadores';

    protected static ?string $navigationLabel = 'Verificar Tomadores';

    protected static ?string $slug = 'financeiro/verificar-tomadores';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Config IT';

    protected static ?int $navigationSort = 26;

    public ?array $data = [];

    /** O legado usava `financial`; agora é do TI (credencial `it`; o admin sempre passa), como o aviso por e-mail. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('it');
    }

    public function mount(): void
    {
        $this->form->fill([
            'data_inicio' => request('data_inicio', today()->toDateString()),
            'data_fim' => request('data_fim', today()->addDays(5)->toDateString()),
            'rows' => [],
        ]);

        // o link do aviso ao TI já traz o período; sem ele, espera o "Buscar" (cada linha consulta a OpenCNPJ)
        if (request()->filled('data_inicio')) {
            $this->search();
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Grid::make(['default' => 1, 'md' => 2])
                    ->schema([
                        DatePicker::make('data_inicio')
                            ->label('Previsão de')
                            ->required(),
                        DatePicker::make('data_fim')
                            ->label('Previsão até')
                            ->required()
                            ->afterOrEqual('data_inicio'),
                    ]),
                Repeater::make('rows')
                    ->hiddenLabel()
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->table(collect(['ID', 'Nome', 'Endereço', 'Número', 'Bairro', 'CEP', 'Cidade', 'UF', 'IBGE', 'País', 'CNPJ', 'Pendências'])
                        ->map(fn (string $label) => TableColumn::make($label))
                        ->all())
                    ->schema([
                        TextInput::make('id')
                            ->readOnly(),
                        TextInput::make('nome'),
                        TextInput::make('endereco'),
                        TextInput::make('numero'),
                        TextInput::make('bairro'),
                        TextInput::make('cep'),
                        TextInput::make('cidade'),
                        TextInput::make('uf'),
                        TextInput::make('ibge'),
                        TextInput::make('pais'),
                        TextInput::make('cnpj'),
                        Placeholder::make('pendencias_text')
                            ->hiddenLabel()
                            ->content(fn (Get $get) => implode(' | ', $get('pendencias') ?? [])),
                        Hidden::make('pendencias'),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('search')
                    ->footer([
                        Actions::make([
                            Action::make('search')
                                ->label('Buscar')
                                ->submit('search'),
                            Action::make('update')
                                ->label('Atualizar cadastro')
                                ->color('warning')
                                ->visible(fn () => filled($this->data['rows'] ?? []))
                                ->requiresConfirmation()
                                ->modalDescription('Os dados da listagem serão gravados em maiúsculas. CNPJs sem cadastro serão criados.')
                                ->action(fn () => $this->updateRegistry()),
                        ]),
                    ]),
            ]);
    }

    public function search(): void
    {
        $state = $this->form->getState();

        $this->data['rows'] = app(PendingTakers::class)($state['data_inicio'], $state['data_fim'], suggest: true);

        if ($this->data['rows'] === []) {
            Notification::make()->success()->title('Nenhuma empresa com pendência de cadastro neste período.')->send();
        }
    }

    public function updateRegistry(): void
    {
        $result = app(UpdateTakers::class)(array_values($this->data['rows'] ?? []), auth()->user()?->usuario_id);

        Notification::make()
            ->title($result['message'])
            ->color($result['ok'] ? ($result['errors'] === [] ? 'success' : 'warning') : 'danger')
            ->send();

        if ($result['ok']) {
            $this->search();
        }
    }
}
