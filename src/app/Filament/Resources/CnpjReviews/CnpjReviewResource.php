<?php

namespace App\Filament\Resources\CnpjReviews;

use App\Actions\Cobranca\Nfse\AddressLookup;
use App\Actions\Cobranca\Nfse\ServiceTaker;
use App\Actions\ConsultaCnpj\RegisterCnpjReview;
use App\Filament\Resources\CnpjReviews\Pages\ListCnpjReviews;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\ConsultaCnpj;
use App\Models\Pessoa;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\HtmlString;
use Throwable;
use UnitEnum;

/** consultaCnpj (legado): consulta de CNPJ dos clientes com embarque nos últimos 3 meses, com validade por quinzena. */
class CnpjReviewResource extends Resource
{
    protected static ?string $model = Pessoa::class;

    protected static ?string $modelLabel = 'Consulta CNPJ';

    protected static ?string $pluralModelLabel = 'Consulta CNPJ Clientes';

    protected static ?string $navigationLabel = 'Consulta CNPJ';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    /** No legado o item é de primeiro nível do menu (sem pai), então fica fora dos grupos. */
    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 75;

    /** security.yml do módulo (legado): financial e import (lista simples = as duas). */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->hasLegacyCredential('financial') && $user->hasLegacyCredential('import'));
    }

    /** Cliente (ou importador/cliente final) de embarque com saída prevista nos últimos 3 meses; importação usa `>` no cliente e `>=` no importador, como o SQL do legado. */
    public static function getEloquentQuery(): Builder
    {
        $since = now()->subMonths(3)->toDateString();

        $shipments = fn (string $type, string $column, string $operator) => fn (QueryBuilder $query) => $query
            ->selectRaw('1')
            ->from('embarque')
            ->whereColumn("embarque.{$column}", 'pessoa.pessoa_id')
            ->where('embarque.impor_expor', $type)
            ->where('embarque.saida_prevista', $operator, $since);

        return parent::getEloquentQuery()
            ->where(fn (Builder $query) => $query
                ->whereExists($shipments('i', 'cliente_id', '>'))
                ->orWhereExists($shipments('i', 'pessoa_id_importador', '>='))
                ->orWhereExists($shipments('e', 'cliente_id', '>'))
                ->orWhereExists($shipments('e', 'pessoa_id_cliente', '>=')))
            ->with('currentCnpjReview');
    }

    /** Campos do diálogo de status; a observação só aparece quando não é OK. */
    public static function reviewFields(): array
    {
        return [
            Select::make('status')
                ->label('Status')
                ->options(ConsultaCnpj::CHOICES)
                ->required()
                ->live(),
            Textarea::make('observacao')
                ->label('Observação')
                ->rows(3)
                ->visible(fn (Get $get) => filled($get('status')) && (int) $get('status') !== ConsultaCnpj::OK),
        ];
    }

    public static function register(int $companyId, array $data): void
    {
        app(RegisterCnpjReview::class)($companyId, (int) $data['status'], $data['observacao'] ?? null, (int) auth()->id());
    }

    /** Cadastro da OpenCNPJ em infolist (o modal pede o schema mais de uma vez, então a consulta fica memorizada por requisição). */
    public static function openCnpjSchema(string $cnpj): array
    {
        static $lookups = [];

        $lookups[$cnpj] ??= ServiceTaker::validCnpj($cnpj)
            ? app(AddressLookup::class)->openCnpjData($cnpj)
            : ['data' => null, 'erro' => 'Informe um CNPJ válido com 14 caracteres.'];

        ['data' => $company, 'erro' => $error] = $lookups[$cnpj];

        if ($error) {
            return [Text::make($error)->color('danger')];
        }

        $date = function (?string $value): ?string {
            try {
                return filled($value) ? Carbon::parse($value)->format('d/m/Y') : null;
            } catch (Throwable) {
                return $value;
            }
        };

        $entry = fn (string $label, mixed $state) => TextEntry::make($label)->label($label)->state($state)->placeholder('Não informado');
        $cep = preg_replace('/^(\d{5})(\d{3})$/', '$1-$2', preg_replace('/\D+/', '', (string) ($company['cep'] ?? '')));
        $status = mb_strtoupper((string) ($company['situacao_cadastral'] ?? ''));

        $normalized = ServiceTaker::normalizeCnpj($cnpj);
        $registered = Pessoa::query()->whereIn('cnpj', array_unique([$cnpj, $normalized, ServiceTaker::formatDocument($normalized)]))->first();

        $name = (string) ($company['razao_social'] ?? 'Empresa');
        $title = $registered && CompanyResource::canEdit($registered)
            ? new HtmlString(sprintf(
                '<a href="%s" target="_blank" class="text-primary-600 hover:underline dark:text-primary-400">%s</a>',
                e(CompanyResource::getUrl('edit', ['record' => $registered])),
                e($name),
            ))
            : $name;

        return [
            Section::make($title)
                ->description(filled($company['nome_fantasia'] ?? null) ? (string) $company['nome_fantasia'] : null)
                ->icon(Heroicon::OutlinedBuildingOffice2)
                ->columns(3)
                ->schema([
                    $entry('CNPJ', ServiceTaker::formatDocument((string) $company['cnpj']))->copyable(),
                    $entry('Situação cadastral', $company['situacao_cadastral'] ?? null)
                        ->badge()
                        ->color(match (true) {
                            $status === '' => 'gray',
                            $status === 'ATIVA' => 'success',
                            default => 'danger',
                        }),
                    $entry('Desde', $date($company['data_situacao_cadastral'] ?? null)),
                    $entry('Matriz / filial', $company['matriz_filial'] ?? null),
                    $entry('Início da atividade', $date($company['data_inicio_atividade'] ?? null)),
                    $entry('Porte', $company['porte_empresa'] ?? null),
                    $entry('Natureza jurídica', $company['natureza_juridica'] ?? null)->columnSpan(2),
                    $entry('Capital social', $company['capital_social'] ?? null),
                    $entry('E-mail', $company['email'] ?? null)->columnSpanFull(),
                ]),
            Section::make('Endereço')
                ->icon(Heroicon::OutlinedMapPin)
                ->columns(4)
                ->schema([
                    $entry('CEP', $cep)->copyable(),
                    $entry('Logradouro', trim(($company['tipo_logradouro'] ?? '').' '.($company['logradouro'] ?? '')))->columnSpan(2),
                    $entry('Número', $company['numero'] ?? null),
                    $entry('Complemento', $company['complemento'] ?? null)->columnSpan(2),
                    $entry('Bairro', $company['bairro'] ?? null),
                    $entry('Município / UF', trim(($company['municipio'] ?? '').' - '.($company['uf'] ?? ''), ' -')),
                ]),
            Section::make('Atividades econômicas (CNAE)')
                ->icon(Heroicon::OutlinedBriefcase)
                ->collapsible()
                ->schema([
                    $entry('Principal', $company['cnae_principal'] ?? null)->badge(),
                    $entry('Outras atividades', array_filter((array) ($company['cnaes_secundarios'] ?? [])))
                        ->badge()
                        ->color('gray'),
                ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('nome')
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Nenhum registro encontrado')
            ->columns([
                TextColumn::make('nome')
                    ->label('Nome')
                    ->url(fn (Pessoa $record) => CompanyResource::canEdit($record) ? CompanyResource::getUrl('edit', ['record' => $record]) : null)
                    ->openUrlInNewTab()
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextInputColumn::make('cnpj')
                    ->label('CNPJ')
                    ->placeholder('N/D')
                    ->rules(['nullable', 'string', 'max:255'])
                    ->searchable(),
                TextColumn::make('currentCnpjReview.created_at')
                    ->label('Data Cadastro')
                    ->date('d/m/Y'),
                TextColumn::make('currentCnpjReview.data_consulta')
                    ->label('Validade Consulta')
                    ->date('d/m/Y'),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (Pessoa $record) => $record->cnpjStatus())
                    ->formatStateUsing(fn (int $state) => ConsultaCnpj::LABELS[$state])
                    ->color(fn (int $state) => ConsultaCnpj::COLORS[$state])
                    ->badge(),
                TextColumn::make('currentCnpjReview.observacao')
                    ->label('Observação')
                    ->wrap(),
            ])
            ->recordActions([
                Action::make('openCnpj')
                    ->label('Consultar CNPJ')
                    ->icon(Heroicon::OutlinedBuildingOffice2)
                    ->iconButton()
                    ->tooltip('Consultar dados cadastrais na OpenCNPJ')
                    ->disabled(fn (Pessoa $record) => blank($record->cnpj))
                    ->slideOver()
                    ->modalWidth(Width::FourExtraLarge)
                    ->modalHeading(fn (Pessoa $record) => 'Dados cadastrais — '.ServiceTaker::formatDocument((string) $record->cnpj))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->schema(fn (Pessoa $record) => self::openCnpjSchema((string) $record->cnpj)),
                Action::make('review')
                    ->label('Alterar status')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading('Muda Status')
                    ->fillForm(fn (Pessoa $record) => ['status' => $record->currentCnpjReview?->status])
                    ->schema(self::reviewFields())
                    ->action(fn (Pessoa $record, array $data) => self::register($record->getKey(), $data)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCnpjReviews::route('/'),
        ];
    }
}
