<?php

namespace App\Filament\Support;

use App\Actions\Booking\Bls\BlContainerRules;
use App\Actions\Booking\Bls\BlDocumentCheck;
use App\Models\Bl;
use App\Models\Container;
use App\Models\Embarque;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/** Campos da Aprovação BL (BlForm + ContainerForm + editAprovacaoBlSuccess, legado), na mesma ordem e com as mesmas regras. */
class BlForm
{
    /** pessoa_id do agente M.C. Logistics: com ele o RNC do BL é obrigatório (validarRncMcLogistics, legado). */
    private const RNC_REQUIRED_AGENT = 43;

    /** País de destino (porto) em que a exportação leva o código de aceite. */
    private const ACCEPTANCE_CODE_COUNTRY = 4;

    /** @return array<int, Component> */
    public static function components(Embarque $booking): array
    {
        $export = $booking->impor_expor === 'e';
        $mcLogistics = (int) $booking->pessoa_id_representante === self::RNC_REQUIRED_AGENT;

        return [
            Section::make('Informações de controle')
                ->compact()
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    TextInput::make('status_display')
                        ->label('Status')
                        ->formatStateUsing(fn (?string $state, ?Bl $record): string => strtoupper((string) $record?->status))
                        ->disabled()
                        ->dehydrated(false),
                    Select::make('habImpressao')
                        ->label('Habilitar Impressão Original')
                        ->options([1 => 'Sim', 0 => 'Não']),
                    TextInput::make('numImpressao')
                        ->label('N° Impressões Possíveis')
                        ->integer(),
                ]),
            Section::make('Informações de contato')
                ->columnSpanFull()
                ->schema([
                    Textarea::make('codigo_aceite')
                        ->label('Codigo aceite')
                        ->visible($export && self::destinationCountry($booking) === self::ACCEPTANCE_CODE_COUNTRY),
                    Grid::make(['default' => 1, 'lg' => 2])
                        ->schema([
                            Group::make([
                                ...self::savedPartyPicker($booking, 'shipper', 'Dados salvos do shipper', $booking->pessoa_id_cliente),
                                ...self::party('shipper', 'Shipper / exporter (complete name and address)', $export),
                                ...self::saveToggle($booking, 'addShipper'),
                                ...self::savedPartyPicker($booking, 'consignee', 'Dados salvos do consignee', $booking->pessoa_id_importador),
                                ...self::party('consignee', 'Consignee (not negotiable unless consigned to order)', ! $export),
                                TextInput::make('rnc')
                                    ->label('RNC')
                                    ->maxLength(255)
                                    ->required($mcLogistics)
                                    ->helperText($mcLogistics ? 'Para embarques da M.C. Logistics é OBRIGATÓRIO o preenchimento do RNC. Caso não possua o dado, informe "N/A".' : null),
                                ...self::saveToggle($booking, 'addCnee'),
                                Textarea::make('representation')
                                    ->label('DMF Representation Agent at Destination')
                                    ->rows(7),
                            ])->columnSpan(1),
                            Group::make([
                                Textarea::make('notify')
                                    ->label('Notify (complete name and address)')
                                    ->rows(7),
                                Textarea::make('special_instr')
                                    ->label('Special Instructions')
                                    ->rows(7),
                                TextInput::make('mbl_number')
                                    ->label('MBL Number')
                                    ->maxLength(50),
                            ])->columnSpan(1),
                        ]),
                ]),
            Section::make('Informações de embarque')
                ->columnSpanFull()
                ->schema([
                    Grid::make(['default' => 1, 'lg' => 3])
                        ->schema([
                            TextInput::make('booking_number')
                                ->label('Booking nr.')
                                ->default($booking->booking)
                                ->disabled()
                                ->dehydrated(false),
                            TextInput::make('bl')
                                ->label('BL')
                                ->required()
                                ->maxLength(255),
                            DatePicker::make('data_emissao_bl')
                                ->label('Data de Emissão do BL'),
                            TextInput::make('vessel')
                                ->label('Vessel - Vg')
                                ->readOnly()
                                ->maxLength(255)
                                ->columnStart(['lg' => 1]),
                            TextInput::make('port_lading')
                                ->label('Port of Loading')
                                ->maxLength(255),
                            TextInput::make('port_trans')
                                ->label('Port of Transhipment')
                                ->maxLength(255)
                                ->columnStart(['lg' => 1]),
                            TextInput::make('port_disc')
                                ->label('Port of Discharge')
                                ->maxLength(255),
                            TextInput::make('place_delivery')
                                ->label('Place of Delivery')
                                ->maxLength(255)
                                ->columnStart(['lg' => 1]),
                            TextInput::make('dmf_ref')
                                ->label('DMF Ref.')
                                ->maxLength(255),
                        ]),
                ]),
            Section::make('Containers')
                ->columnSpanFull()
                ->schema(self::containers($booking)),
            Section::make('Particulars furnished by shipper')
                ->columnSpanFull()
                ->schema([
                    Grid::make(['default' => 1, 'lg' => 3])
                        ->schema([
                            Textarea::make('mark_nos')
                                ->label('Mark & nos/container')
                                ->rows(8),
                            Textarea::make('num_pkgs')
                                ->label('N of pkgs')
                                ->rows(8),
                            Textarea::make('desc_pkgs')
                                ->label('Description of packages and goods')
                                ->rows(8),
                        ]),
                    Grid::make(['default' => 1, 'sm' => 2, 'lg' => 4])
                        ->schema([
                            TextInput::make('gross_weight')
                                ->label('Gross Weight (KGS)')
                                ->maxLength(255),
                            TextInput::make('net_weight')
                                ->label('Net Weight (KGS)')
                                ->maxLength(255),
                            TextInput::make('volume')
                                ->label('Volume (m³)')
                                ->maxLength(255),
                            TextInput::make('invoice')
                                ->label('Invoice')
                                ->maxLength(255),
                        ]),
                ]),
            Section::make('Informações adicionais')
                ->columnSpanFull()
                ->schema([
                    Grid::make(['default' => 1, 'lg' => 2])
                        ->schema([
                            Textarea::make('obs')
                                ->label('*applicable only when used as through bill of lading')
                                ->rows(3),
                            Textarea::make('observation')
                                ->label('Observations')
                                ->rows(3),
                        ]),
                    Grid::make(['default' => 1, 'sm' => 2, 'lg' => 4])
                        ->schema([
                            Select::make('freight_at')
                                ->label('Freight payable at')
                                ->options(Bl::FREIGHT_AT)
                                ->required($export),
                            TextInput::make('num_original')
                                ->label('Number of original b(s)/l')
                                ->maxLength(255),
                            TextInput::make('shipped_board')
                                ->label('Shipped on Board')
                                ->maxLength(255),
                            TextInput::make('place_date')
                                ->label('Place and date of issue')
                                ->maxLength(255),
                        ]),
                ]),
        ];
    }

    /**
     * Shipper (expo) e consignee (impo): o texto precisa de CNPJ/CPF válido, senão o aviso aparece e quem salva confirma.
     *
     * @return array<int, Component>
     */
    private static function party(string $field, string $label, bool $checkDocument): array
    {
        $warning = fn (Get $get): ?string => $checkDocument ? (app(BlDocumentCheck::class)($get($field))['message'] ?: null) : null;

        return [
            Textarea::make($field)
                ->label($label)
                ->rows(7)
                ->live(onBlur: true)
                ->helperText($warning),
            Toggle::make("confirm_no_document_{$field}")
                ->label('Sem CNPJ válido não será possível emitir as Instruções de Cobrança(s) e Nota(s) Fiscal(is) para este embarque. Salvar mesmo assim')
                ->visible(fn (Get $get): bool => $warning($get) !== null)
                ->accepted(fn (Get $get): bool => $warning($get) !== null)
                ->validationAttribute('confirmação de CNPJ')
                ->dehydrated(false),
        ];
    }

    /** @return array<int, Component> "Salvar dados" (só importação): guarda o texto para os próximos BLs. */
    private static function saveToggle(Embarque $booking, string $name): array
    {
        return $booking->impor_expor === 'i' ? [Toggle::make($name)->label('Salvar dados')] : [];
    }

    /**
     * Impo: os dados já salvos do cliente/importador, para trazer de volta ao campo (os "accordions" do legado).
     *
     * @return array<int, Component>
     */
    private static function savedPartyPicker(Embarque $booking, string $field, string $label, ?int $personId): array
    {
        if ($booking->impor_expor !== 'i' || ! $personId) {
            return [];
        }

        return [
            Select::make("saved_{$field}")
                ->label($label)
                ->options(fn (): array => DB::table('cmn_dados_bl')->where('ref_pessoa', $personId)->pluck('dados', 'id')->map(fn (string $dados) => mb_substr($dados, 0, 50))->all())
                ->live()
                ->afterStateUpdated(fn ($state, $set) => $state ? $set($field, DB::table('cmn_dados_bl')->where('ref_pessoa', $personId)->where('id', $state)->value('dados')) : null)
                ->dehydrated(false),
        ];
    }

    /** Campos de um container que o "copiar" do legado leva para a linha de entrada: o resto (número, tara e lacres) é de cada equipamento. */
    public const COPIED_FIELDS = ['tipo_container_id', 'temperatura', 'qty_pkgs', 'gross_weight', 'net_weight', 'volume'];

    /**
     * Os containers do BL como no legado: uma linha de entrada (Adicionar / Salvar / Cancelar, com "Manter valores") e,
     * embaixo, a lista com editar, copiar e excluir. Os métodos dos botões estão na página (ManagesBl). Ao mudar a lista,
     * peso bruto, peso líquido e volume do BL passam a ser a soma dos containers.
     *
     * @return array<int, Component>
     */
    private static function containers(Embarque $booking): array
    {
        $export = $booking->impor_expor === 'e';
        $reefer = fn (Get $get): bool => $export && Container::isReefer($get('container_entry.tipo_container_id'));
        $entry = fn (Field $field): Field => $field->dehydrated(false);

        return [
            Hidden::make('containers')
                ->default([]),
            Hidden::make('container_editing')
                ->dehydrated(false),
            Grid::make(['default' => 2, 'lg' => 12])
                ->schema(array_values(array_filter([
                    $entry(Select::make('container_entry.tipo_container_id')
                        ->label('Size/Type')
                        ->live()
                        ->options(fn (): array => DB::table('tipo_container')
                            ->whereIn('tipo_container_id', DB::table('mercadoria')->where('embarque_id', $booking->getKey())->select('tipo_container_id'))
                            ->orderBy('nome')
                            ->pluck('nome', 'tipo_container_id')
                            ->all()))
                        ->columnSpan(['default' => 2, 'lg' => $export ? 3 : 4]),
                    $export ? $entry(Select::make('container_entry.temperatura')
                        ->label('Temperatura')
                        ->options(collect(range(25, -25))->mapWithKeys(fn (int $t) => [$t => "{$t}°"])->all())
                        ->visible($reefer))
                        ->columnSpan(['default' => 1, 'lg' => 1]) : null,
                    $entry(TextInput::make('container_entry.numero')->label('Container n'))
                        ->columnSpan(['default' => 2, 'lg' => 3]),
                    $entry(TextInput::make('container_entry.tara')->label('Tara'))
                        ->columnSpan(['default' => 1, 'lg' => 2]),
                    $entry(TextInput::make('container_entry.seals_n1')->label('Seals n'))
                        ->columnSpan(['default' => 1, 'lg' => 1]),
                    $entry(TextInput::make('container_entry.seals_n2')->label('Seals n 2'))
                        ->columnSpan(['default' => 1, 'lg' => 1]),
                    $entry(TextInput::make('container_entry.seals_n3')->label('Seals n 3'))
                        ->columnSpan(['default' => 1, 'lg' => 1]),
                    $entry(TextInput::make('container_entry.qty_pkgs')->label('Qty of Packages'))
                        ->columnSpan(['default' => 1, 'lg' => 3]),
                    $entry(TextInput::make('container_entry.gross_weight')->label('Gross Weight'))
                        ->columnSpan(['default' => 1, 'lg' => 3]),
                    $entry(TextInput::make('container_entry.net_weight')->label('Net Weight'))
                        ->columnSpan(['default' => 1, 'lg' => 3]),
                    $entry(TextInput::make('container_entry.volume')->label('Volume'))
                        ->columnSpan(['default' => 1, 'lg' => 3]),
                ]))),
            Flex::make([
                $entry(Checkbox::make('container_keep')
                    ->label('Manter valores')),
                Actions::make([
                    Action::make('addContainer')
                        ->label(fn (Get $get): string => filled($get('container_editing')) ? 'Salvar' : 'Adicionar')
                        ->icon(Heroicon::OutlinedPlus)
                        ->action('addContainer'),
                    Action::make('cancelContainerEdit')
                        ->label('Cancelar')
                        ->color('gray')
                        ->visible(fn (Get $get): bool => filled($get('container_editing')))
                        ->action('cancelContainerEdit'),
                ]),
            ])->from('md'),
            View::make('filament.bls.containers')
                ->viewData(['export' => $export]),
            Select::make('displayContainers')
                ->label('Exibir Containers na Descrição')
                ->options([1 => 'Sim', 0 => 'Não']),
        ];
    }

    /**
     * Soma dos containers (a vírgula é o decimal) com três casas, como o legado (updateContainerSuccess).
     *
     * @param  array<int|string, array<string, mixed>>  $containers
     * @return array{gross_weight: string, net_weight: string, volume: string}
     */
    public static function totals(array $containers): array
    {
        $sum = fn (string $field): string => number_format(collect($containers)->sum(fn (array $container): float => BlContainerRules::number($container[$field] ?? null)), 3, ',', '.');

        return ['gross_weight' => $sum('gross_weight'), 'net_weight' => $sum('net_weight'), 'volume' => $sum('volume')];
    }

    private static function destinationCountry(Embarque $booking): ?int
    {
        $country = DB::table('porto')->where('porto_id', $booking->porto_destino_id)->value('ref_pais');

        return $country === null ? null : (int) $country;
    }
}
