<?php

namespace App\Filament\Resources\Embarques\Schemas;

use App\Actions\Booking\Creation\BookingParties;
use App\Actions\Booking\Creation\CreateBooking;
use App\Actions\Booking\Creation\NextBookingNumber;
use App\Actions\Booking\Creation\QuotationPrefill;
use App\Actions\Booking\Creation\RegisterFinalDestination;
use App\Actions\Booking\Creation\TransitTimeCheck;
use App\Actions\Booking\UpdateBooking;
use App\Actions\Companies\SaveCompany;
use App\Filament\Support\ReportFields;
use App\Models\Container;
use App\Models\Embarque;
use App\Models\Pessoa;
use App\Models\Porto;
use Closure;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * Formulário da reserva, na criação e na edição: os quatro do legado (EmbarqueForm e EmbarqueAereoForm, importação e exportação).
 * `$operation`: i = importação, e = exportação; `$mode`: m = marítimo, a = aéreo; `$booking`: a reserva em edição.
 *
 * Na edição as listas aceitam o valor que a reserva já tem, mesmo que ele não entraria numa reserva nova.
 */
class EmbarqueForm
{
    private const YES_NO = ['s' => 'SIM', 'n' => 'NÃO'];

    /** vendedor/ajax (legado): vendedores de sistema, que não entram na busca. */
    private const HIDDEN_SELLERS = [155, 28, 37, 80, 125, 177];

    /** DMF Logistics do Brasil como agente: sempre na lista da exportação e com BL direto. */
    private const DMF_AGENT = 3198;

    /** Colunas da mercadoria que a edição carrega no formulário. */
    private const GOODS_FIELDS = [
        'mercadoria_id', 'nome', 'quantidade', 'tipo_container_id', 'temperatura', 'gross_weight', 'net_weight', 'cubature',
        'width', 'height', 'depth', 'free_time', 'quimico', 'carga_perigosa', 'super_testado',
    ];

    public function __construct(private string $operation, private string $mode, private ?Embarque $booking = null) {}

    /**
     * O que a reserva em edição põe no formulário. Os vazios do legado ('', data zerada e 0 nas chaves) entram como nulo.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function state(array $attributes): array
    {
        $blank = fn (mixed $value, string $column) => UpdateBooking::isEmpty($column, $value) ? null : $value;
        $state = collect($attributes)->map($blank)->all();

        $parties = app(BookingParties::class);
        $id = $this->booking->getKey();

        $state['contacts'] = collect(BookingParties::PARTIES)
            ->map(fn (array $definition, string $party) => $parties->linkedContacts($this->booking, $party))
            ->all();
        $state['goods'] = DB::table('mercadoria')
            ->where('embarque_id', $id)
            ->orderBy('mercadoria_id')
            ->get(self::GOODS_FIELDS)
            ->map(fn (object $good) => collect((array) $good)->map($blank)->all())
            ->all();
        $state['instrucao_especial'] = DB::table('instrucao_especial_embarque')->where('embarque_id', $id)->orderBy('instrucao_especial_embarque_id')->value('instrucao');
        $state['pais_id'] = $this->agentCountry();

        $etd = $state['saida_prevista'] ?? null;
        $eta = $state['chegada_prevista'] ?? null;
        $transit = $etd && $eta ? (int) Carbon::parse($etd)->startOfDay()->diffInDays(Carbon::parse($eta)->startOfDay()) : null;
        // Há reservas com ETA antes do ETD: o campo fica vazio em vez de travar o formulário.
        $state['transit'] = $transit >= 0 && $transit <= 999 ? $transit : null;

        return $state;
    }

    /** O "país de destino" não é gravado: o do porto de destino, se o agente atende; senão, o primeiro país do agente. */
    private function agentCountry(): ?int
    {
        $destination = DB::table('porto')->where('porto_id', $this->booking->porto_destino_id)->value('ref_pais');
        $served = DB::table('agente_pais')->where('agente_id', $this->booking->pessoa_id_representante)->where('restricao', 0)->orderBy('ordem')->pluck('pais_id');
        $country = $served->contains($destination) ? $destination : ($served->first() ?? $destination);

        return $country ? (int) $country : null;
    }

    /** O valor atual da reserva em edição, para a lista não recusar o que já está gravado (0 não casa com chave nenhuma). */
    private function current(string $column): int|string
    {
        return $this->booking?->getAttribute($column) ?: 0;
    }

    public function configure(Schema $schema): Schema
    {
        $sections = $this->isMaritimeExport()
            ? [$this->quotation(), $this->booking(), $this->client(), $this->party('exportador'), $this->party('importador'), $this->shipment(), $this->goods(), $this->deadlines()]
            : [$this->quotation(), $this->booking(), $this->shipment(), $this->client(), $this->party('importador'), $this->party('notify'), $this->party('exportador'), $this->goods()];

        return $schema
            ->columns(1)
            ->components(array_values(array_filter($sections)));
    }

    private function isMaritimeExport(): bool
    {
        return $this->operation === 'e' && $this->mode === 'm';
    }

    private function quotation(): ?Section
    {
        // A edição do legado só mostra a cotação vinculada, não troca.
        $source = $this->booking ? null : QuotationPrefill::source($this->operation, $this->mode);

        if (! $source) {
            return null;
        }

        [$table, $column] = $source;

        return Section::make('Cotação')
            ->schema([
                Select::make($column)
                    ->label('Cotação')
                    ->helperText('Escolher a cotação importa os dados da referência para o formulário.')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => DB::table($table)
                        // A cotação de exportação não tem referência gravada: o legado busca pelo número.
                        ->when(
                            $table === 'cotacao_expo',
                            fn (Builder $q) => $q->where($column, (int) preg_replace('/\D/', '', $search)),
                            fn (Builder $q) => $q->where('referencia', 'like', "%{$search}%"),
                        )
                        ->orderByDesc($column)
                        ->limit(30)
                        ->get()
                        ->mapWithKeys(fn (object $row) => [$row->{$column} => $row->referencia ?? "Cotação {$row->{$column}}"])
                        ->all())
                    ->getOptionLabelUsing(fn ($value) => DB::table($table)->where($column, $value)->value($table === 'cotacao_expo' ? $column : 'referencia'))
                    ->live()
                    ->afterStateUpdated(function ($state, Set $set) {
                        foreach ($state ? app(QuotationPrefill::class)($this->operation, $this->mode, (int) $state) : [] as $field => $value) {
                            $set($field, $field === 'goods' ? collect($value)->keyBy(fn () => (string) str()->uuid())->all() : $value);
                        }
                    }),
            ]);
    }

    private function booking(): Section
    {
        $maritime = $this->mode === 'm';
        $export = $this->operation === 'e';

        return Section::make('Informações da reserva')
            ->columns(3)
            ->schema([
                Select::make('vendedor_id')
                    ->label('Vendedor')
                    ->required()
                    ->searchable()
                    ->options(fn () => DB::table('vendedor')
                        ->where(fn (Builder $q) => $q->whereNull('deleted_at')->whereNotIn('vendedor_id', self::HIDDEN_SELLERS))
                        ->orWhere('vendedor_id', $this->current('vendedor_id'))
                        ->orderBy('nome')
                        ->pluck('nome', 'vendedor_id')),
                DatePicker::make('data_topo')
                    ->label('Data de solicitação')
                    ->required()
                    ->default(today()->toDateString()),
                $this->bookingNumber(),
                ...($this->isMaritimeExport() ? [
                    ReportFields::country('pais_id', 'País de destino')
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('pessoa_id_representante', null)),
                ] : []),
                $this->agent(),
                ...($maritime && ! $export ? [
                    Select::make('escritorio_id')
                        ->label('Escritório do agente')
                        ->options(fn (Get $get) => DB::table('escritorio')
                            ->where('pessoa_id', $get('pessoa_id_representante'))
                            ->orWhere('escritorio_id', $this->current('escritorio_id'))
                            ->orderBy('nome')
                            ->pluck('nome', 'escritorio_id')),
                ] : []),
                Select::make('de')
                    ->label($export ? 'Responsável pelo fechamento' : 'Responsável')
                    ->required($this->mode === 'a' || $export)
                    ->searchable()
                    ->options(fn () => $this->responsibleOptions())
                    ->default($export ? null : auth()->user()->nome),
                ...($export ? [] : [
                    TextInput::make('de_fone')
                        ->label('Telefone do responsável')
                        ->default(auth()->user()->fone),
                ]),
                ...($this->isMaritimeExport() ? [
                    Select::make('tipo_fechamento')
                        ->label('Tipo de fechamento')
                        ->required()
                        ->options(['o' => 'Origem / DMF', 'd' => 'Destino / Agente']),
                    Select::make('bl_direto')
                        ->label('BL direto')
                        ->options([0 => 'NÃO', 1 => 'SIM'])
                        ->default(0)
                        ->selectablePlaceholder(false),
                ] : []),
                TextInput::make('observacaoint')
                    ->label('Freight status'),
                ...($this->mode === 'a' && ! $export ? [
                    TextInput::make('peso_cotacao')
                        ->label('Peso cotação')
                        ->numeric()
                        ->default(0),
                    TextInput::make('volume_cotacao')
                        ->label('Volume cotação')
                        ->numeric()
                        ->default(0),
                ] : []),
                Textarea::make('invoice')
                    ->label('Invoice'),
                Textarea::make('customer_ref')
                    ->label('Customer reference'),
                Textarea::make('house_bl')
                    ->label('House BL'),
                TextInput::make('master_bl')
                    ->label('Master BL'),
                ...($maritime ? [
                    TextInput::make('qtd_bl')
                        ->label('Quantidade House BL')
                        ->integer(),
                    ...($export ? [] : [
                        Textarea::make('nr_container')
                            ->label('Containers numbers'),
                        TextInput::make('ce_mercante')
                            ->label('CE Mercante'),
                    ]),
                    DatePicker::make('data_validade')
                        ->label('Validade da cotação')
                        ->required($export)
                        ->live(),
                    ...$this->validityJustification(),
                    Select::make('consolidado')
                        ->label('Consolidado')
                        ->options(self::YES_NO)
                        ->default('n')
                        // Reserva antiga sem resposta aparece em branco, não como a primeira opção.
                        ->selectablePlaceholder((bool) $this->booking && blank($this->booking->consolidado)),
                    Select::make('tipo_frete')
                        ->label('Local de pagamento do frete')
                        ->options(['p' => 'PP', 'c' => 'CC', 'a' => 'ABROAD']),
                ] : []),
                ...($this->isMaritimeExport() ? [
                    Textarea::make('instrucao_especial')
                        ->label('Instrução especial deste embarque')
                        ->helperText('Deixe em branco se não possui.')
                        ->columnSpanFull(),
                ] : []),
            ]);
    }

    /** Edição da exportação marítima: trocar a validade pede justificativa, com o arquivo da negociação opcional. */
    private function validityJustification(): array
    {
        if (! $this->booking || ! $this->isMaritimeExport()) {
            return [];
        }

        $changed = fn (Get $get) => UpdateBooking::changesValidity($this->booking, $get('data_validade'));

        return [
            Textarea::make('justificativa_validade')
                ->label('Justifique a alteração da data de validade')
                ->required()
                ->visible($changed)
                ->columnSpanFull(),
            FileUpload::make('arquivo_validade')
                ->label('Arquivo da negociação')
                ->storeFiles(false)
                ->maxSize(20480)
                ->visible($changed)
                ->columnSpanFull(),
        ];
    }

    /** Na importação o número vem do botão (legado: campo bloqueado); na exportação é o número do armador, digitado. */
    private function bookingNumber(): TextInput
    {
        $field = TextInput::make('booking')
            ->label('Booking')
            ->required();

        if ($this->operation === 'e') {
            return $field;
        }

        return $field
            ->readOnly()
            ->suffixAction(Action::make('solicitarNumero')
                ->label('Solicitar número')
                ->button()
                ->requiresConfirmation()
                ->action(function (Get $get, Set $set) {
                    if (! $get('vendedor_id')) {
                        return Notification::make()->title('Defina o Vendedor antes de solicitar o número do Booking.')->danger()->send();
                    }

                    try {
                        $set('booking', app(NextBookingNumber::class)((int) $get('vendedor_id'), $this->mode));
                    } catch (DomainException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }));
    }

    /** agente/ajax e agente/combo (legado): na exportação marítima, os agentes do país de destino, na ordem de preferência. */
    private function agent(): Select
    {
        $field = Select::make('pessoa_id_representante')
            ->label('Agente')
            ->required($this->mode === 'm')
            ->searchable()
            ->live()
            ->afterStateUpdated(function ($state, Set $set) {
                $set('escritorio_id', null);

                if ($this->isMaritimeExport()) {
                    $set('bl_direto', (int) $state === self::DMF_AGENT ? 1 : 0);
                }
            })
            ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome'));

        if ($this->isMaritimeExport()) {
            return $field->options(fn (Get $get) => DB::table('pessoa as p')
                ->leftJoin('agente_pais as a', fn ($join) => $join->on('a.agente_id', '=', 'p.pessoa_id')->where('a.pais_id', $get('pais_id'))->where('a.restricao', 0))
                ->where(fn (Builder $q) => $q->whereNotNull('a.agente_id')->orWhereIn('p.pessoa_id', [self::DMF_AGENT, $this->current('pessoa_id_representante')]))
                ->orderBy('a.ordem')
                ->orderBy('p.nome')
                ->pluck('p.nome', 'p.pessoa_id'));
        }

        return $field->getSearchResultsUsing(fn (string $search) => Pessoa::query()
            ->where('nome', 'like', "%{$search}%")
            ->whereExists(fn (Builder $q) => $q->from('pessoa_tipo_pessoa as t')->whereColumn('t.pessoa_id', 'pessoa.pessoa_id')->where('t.tipo_pessoa_id', 1))
            ->whereExists(fn (Builder $q) => $q->from('agente_pais as a')->whereColumn('a.agente_id', 'pessoa.pessoa_id')->where('a.restricao', 0))
            ->orderBy('nome')
            ->limit(30)
            ->pluck('nome', 'pessoa_id'));
    }

    /** common/usuariosImpo e usuariosExpo (legado): quem tem a credencial da operação e a de fechamento. A reserva guarda o nome. */
    private function responsibleOptions(): array
    {
        $names = DB::table('usuario')
            ->where('status', 1)
            ->where('credentials', 'like', $this->operation === 'e' ? '%export%' : '%import%')
            ->where('credentials', 'like', '%fechamento%')
            ->orderBy('nome')
            ->pluck('nome')
            ->when($this->operation === 'i', fn ($names) => $names->push(auth()->user()->nome))
            ->push($this->booking?->de)
            ->filter()
            ->unique();

        return $names->combine($names)->all();
    }

    private function shipment(): Section
    {
        $components = match (true) {
            $this->mode === 'a' => [
                Select::make('ref_cia_aerea')
                    ->label('Cia aérea')
                    ->searchable()
                    ->options(fn () => DB::table('cia_aerea')->orderBy('name')->pluck('name', 'id')),
                $this->etd(),
                $this->transit(),
                $this->date('chegada_prevista', 'ETA'),
                $this->loadingType(),
                $this->port('porto_origem_id', 'Origem'),
                $this->port('porto_destino_id', 'Destino'),
                TextInput::make('via')
                    ->label('Via'),
                $this->incoterm(),
                $this->insurance(),
                $this->insuranceJustification(),
                $this->note('embarque_obs', 'Obs. do embarque'),
            ],
            $this->operation === 'e' => [
                $this->carrier()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, Set $set) {
                        $days = DB::table('armador')->where('armador_id', $state)->value('free_time_padrao');
                        $days && $set('free_time_detention', $days);
                    }),
                ReportFields::ship('navio_id', 'Navio'),
                ReportFields::ship('navio_id_destino', 'Navio de destino'),
                TextInput::make('vg')
                    ->label('VG'),
                $this->etd(),
                $this->transit(),
                $this->date('chegada_prevista', 'ETA'),
                $this->incoterm(),
                Select::make('type_of_service')
                    ->label('Tipo de serviço')
                    ->options([
                        'aa' => 'AIR TO AIR', 'ad' => 'AIR TO DOOR', 'da' => 'DOOR TO AIRPORT', 'dd' => 'DOOR TO DOOR', 'dp' => 'DOOR/PORT', 'pd' => 'PORT/DOOR',
                        'pp' => 'PORT/PORT', 'rd' => 'RAIL/DOOR', 'rp' => 'RAIL/PORT', 'te' => 'AIR FREIGHT', 'tt' => 'TRUCK/TRUCK',
                    ]),
                Select::make('flag_fi')
                    ->label('Freight Invoice')
                    ->options([0 => 'Não', 1 => 'Sim'])
                    ->live(),
                Select::make('pessoa_id_pagador')
                    ->label('Pagador')
                    ->searchable()
                    ->required()
                    ->visible(fn (Get $get) => (int) $get('flag_fi') === 1)
                    ->getSearchResultsUsing(fn (string $search) => Pessoa::query()
                        ->where('nome', 'like', "%{$search}%")
                        ->whereExists(fn (Builder $q) => $q->from('pessoa_tipo_pessoa as t')->whereColumn('t.pessoa_id', 'pessoa.pessoa_id')->where('t.tipo_pessoa_id', 3))
                        ->orderBy('nome')
                        ->limit(30)
                        ->pluck('nome', 'pessoa_id'))
                    ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome'))
                    // pessoa/addPagadorShow (legado): o pagador novo entra como importador (tipo 3).
                    ->createOptionModalHeading('Novo pagador')
                    ->createOptionForm([
                        TextInput::make('nome')
                            ->label('Nome')
                            ->required(),
                        TextInput::make('razao_social')
                            ->label('Razão social')
                            ->required(),
                        TextInput::make('cnpj')
                            ->label('CNPJ'),
                        TextInput::make('endereco')
                            ->label('Endereço'),
                        ...$this->location('paises_id', 'estados_id', 'cidades_id'),
                    ])
                    ->createOptionUsing(fn (array $data) => app(SaveCompany::class)([...$data, 'tipo_empresa' => [3]], auth()->id())->getKey()),
                Select::make('recebedor_freight_invoice')
                    ->label('Recebedor')
                    ->required()
                    ->visible(fn (Get $get) => (int) $get('flag_fi') === 1)
                    ->options(['d' => 'DMF INTERNACIONAL', 'a' => 'AIC', 'j' => 'JADE']),
                $this->note('place_of_receipt', 'Place of receipt'),
                $this->port('porto_origem_id', 'Origem'),
                $this->port('porto_destino_id', 'Destino'),
                $this->finalDestination(),
                Select::make('inland')
                    ->label('Inland')
                    ->options([1 => 'Armador', 2 => 'Agente']),
                TextInput::make('via')
                    ->label('Via')
                    ->required(),
                TextInput::make('free_time_detention')
                    ->label('Free time detention (origem)')
                    ->required()
                    ->integer(),
                TextInput::make('free_time')
                    ->label('Free time (destino)')
                    ->integer(),
                $this->note('obs_free_time_destino', 'Obs. free time (destino)'),
                $this->note('embarque_obs', 'Obs. do embarque'),
            ],
            default => [
                ReportFields::ship('navio_id', 'Navio'),
                ReportFields::ship('navio_id_destino', 'Navio de destino'),
                TextInput::make('vg')
                    ->label('VG'),
                $this->etd(),
                $this->transit(),
                $this->date('chegada_prevista', 'ETA'),
                $this->date('chegada_desejada', 'ETA desejado'),
                $this->loadingType(),
                $this->carrier()
                    ->live(),
                $this->carrier('coloader_id', 'Coloader'),
                TextInput::make('free_time')
                    ->label('Free time (destino)')
                    ->integer(),
                $this->incoterm(),
                $this->date('data_entrega_container_origem', 'Entrega do container na origem'),
                $this->port('porto_origem_id', 'Origem'),
                $this->port('porto_destino_id', 'Destino'),
                TextInput::make('via')
                    ->label('Via'),
                Select::make('garantee')
                    ->label('Security deposit')
                    ->options(['n' => 'NO', 's' => 'YES', '3' => '30 DAYS', '4' => '45 DAYS', '5' => '50 DAYS', '6' => '60 DAYS', '9' => '90 DAYS']),
                $this->insurance(),
                $this->insuranceJustification(),
                Select::make('demurrage')
                    ->label('Gerou demurrage')
                    ->options(self::YES_NO),
                TextInput::make('demurrage_days')
                    ->label('Dias de demurrage')
                    ->integer(),
                Select::make('ctr_redestinacao')
                    ->label('Redestinação')
                    ->options([1 => 'Pendente', 2 => 'OK']),
                Select::make('ctrl_autorizacao_impo')
                    ->label('Autorizado')
                    ->options([1 => 'SIM', 2 => 'NÃO'])
                    ->default(1)
                    ->selectablePlaceholder(false),
                $this->note('embarque_obs', 'Obs. do embarque'),
            ],
        };

        return Section::make('Informações do embarque')
            ->columns(3)
            ->schema($components);
    }

    /** "Transit Time" do legado: ETD + dias preenche o ETA. Não é gravado. Na importação marítima mostra os avisos do trecho. */
    private function transit(): TextInput
    {
        $warns = $this->operation === 'i' && $this->mode === 'm';

        return TextInput::make('transit')
            ->label('Transit time (dias)')
            ->integer()
            ->minValue(0)
            ->maxValue(999)
            ->dehydrated(false)
            ->live(onBlur: true)
            ->afterStateUpdated(function ($state, Get $get, Set $set) {
                if (ctype_digit((string) $state) && (int) $state <= 999 && $get('saida_prevista')) {
                    $set('chegada_prevista', Carbon::parse($get('saida_prevista'))->addDays((int) $state)->toDateString());
                }
            })
            ->helperText(fn ($state, Get $get) => $this->warningList($warns ? app(TransitTimeCheck::class)(
                $get('porto_origem_id'),
                $get('porto_destino_id'),
                $get('armador_id'),
                $get('pessoa_id_importador'),
                $state,
            ) : []) ?? 'Com o ETD preenchido, calcula o ETA.');
    }

    /** @param  list<string>  $warnings */
    private function warningList(array $warnings): ?HtmlString
    {
        return $warnings ? new HtmlString(collect($warnings)
            ->map(fn (string $warning) => '<span style="color: #dc2626">'.e($warning).'</span>')
            ->implode('<br>')) : null;
    }

    private function date(string $name, string $label): DatePicker
    {
        return DatePicker::make($name)
            ->label($label);
    }

    /** Depois da confirmação de embarque a saída prevista não muda mais. */
    private function etd(): DatePicker
    {
        $confirmed = (bool) $this->booking?->departureConfirmations()->exists();

        return $this->date('saida_prevista', 'ETD')
            ->disabled($confirmed)
            ->dehydrated()
            ->helperText($confirmed ? 'O embarque já foi confirmado.' : null);
    }

    private function note(string $name, string $label): Textarea
    {
        return Textarea::make($name)
            ->label($label)
            ->columnSpanFull();
    }

    private function loadingType(): Select
    {
        return Select::make('loading_type')
            ->label('Loading type')
            ->options(['d' => 'DMF', 'f' => 'Free Hand']);
    }

    private function carrier(string $name = 'armador_id', string $label = 'Armador'): Select
    {
        return ReportFields::carrier($name, $label);
    }

    private function incoterm(): Select
    {
        return Select::make('incoterm_id')
            ->label('Incoterm')
            ->options(fn () => DB::table('incoterm')->orderBy('nome')->pluck('nome', 'incoterm_id'));
    }

    /** O destino final é um endereço do cliente; o "+" cadastra um novo, depois de escolhido o cliente. */
    private function finalDestination(): Select
    {
        $unitedStates = fn (Get $get) => (int) $get('pais_id') === RegisterFinalDestination::UNITED_STATES;

        return Select::make('destino_final_id')
            ->label('Final destination')
            ->options(fn (Get $get) => app(RegisterFinalDestination::class)->options($get('cliente_id'), $this->booking?->destino_final_id))
            ->helperText('Escolha o cliente para ver e cadastrar os endereços dele.')
            ->createOptionModalHeading('Novo destino final')
            ->createOptionForm([
                ...$this->location('pais_id', 'estado_id', 'cidade_id', $unitedStates, true),
                Textarea::make('endereco')
                    ->label('Endereço')
                    ->required($unitedStates),
                TextInput::make('zip_code')
                    ->label('ZIP code')
                    ->required($unitedStates),
            ])
            ->createOptionUsing(fn (array $data, Get $get) => app(RegisterFinalDestination::class)($data, (int) $get('cliente_id')))
            ->createOptionAction(fn (Action $action) => $action->visible(fn (Get $get) => filled($get('cliente_id'))));
    }

    /**
     * País, estado e cidade encadeados. Sem estado escolhido, a cidade lista as do país.
     *
     * @return list<Select>
     */
    private function location(string $country, string $state, string $city, bool|Closure $stateRequired = false, bool $saveState = false): array
    {
        return [
            Select::make($country)
                ->label('País')
                ->required()
                ->searchable()
                ->live()
                ->afterStateUpdated(function (Set $set) use ($state, $city) {
                    $set($state, null);
                    $set($city, null);
                })
                ->options(fn () => DB::table('paises')->orderBy('nome')->pluck('nome', 'paises_id')),
            Select::make($state)
                ->label('Estado')
                ->required($stateRequired)
                ->searchable()
                ->live()
                ->dehydrated($saveState)
                ->afterStateUpdated(fn (Set $set) => $set($city, null))
                ->options(fn (Get $get) => DB::table('estados')->where('paises_id', $get($country))->orderBy('nome')->pluck('nome', 'estados_id')),
            Select::make($city)
                ->label('Cidade')
                ->required()
                ->searchable()
                ->options(fn (Get $get) => DB::table('cidades')
                    ->when(
                        $get($state),
                        fn (Builder $q, $id) => $q->where('estados_id', $id),
                        fn (Builder $q) => $q->whereIn('estados_id', DB::table('estados')->where('paises_id', $get($country))->select('estados_id')),
                    )
                    ->orderBy('nome')
                    ->pluck('nome', 'cidades_id')),
        ];
    }

    /** Portos (marítimo: porto e porto seco) ou aeroportos, por nome ou UN/Code. */
    private function port(string $name, string $label): Select
    {
        $field = Select::make($name)
            ->label($label)
            ->required()
            ->searchable()
            ->getSearchResultsUsing(fn (string $search) => Porto::query()
                ->whereIn('tipo', $this->mode === 'm' ? ['p', 's'] : ['a'])
                ->where(fn ($q) => $q->where('nome', 'like', "{$search}%")->orWhere('uncode', $search))
                ->orderBy('nome')
                ->limit(30)
                ->get()
                ->mapWithKeys(fn (Porto $port) => [$port->getKey() => "{$port->nome} ({$port->uncode})"]))
            ->getOptionLabelUsing(fn ($value) => Porto::find($value)?->label);

        if ($name === 'porto_origem_id') {
            $field->live();
        }

        // O despachante DMF acompanha o porto de destino (verificaDespachantePadrao).
        if ($name === 'porto_destino_id' && ! $this->isMaritimeExport()) {
            $field
                ->live()
                ->afterStateUpdated(function ($state, Set $set) {
                    $set('despachante_dmf_id', DB::table('porto')->where('porto_id', $state)->value('despachante_id'));
                });
        }

        return $field;
    }

    private function insurance(): Select
    {
        // No marítimo o legado deixa passar em branco; a edição não obriga a responder o que a reserva nunca respondeu.
        $required = $this->mode === 'a' || ! $this->booking || filled($this->booking->insurance);

        return Select::make('insurance')
            ->label('Seguro contratado')
            ->required($required)
            ->options(self::YES_NO)
            ->default($this->mode === 'a' ? 'n' : null)
            ->live()
            ->helperText($this->mode === 'm' ? 'SIM cria a reserva de seguro vinculada.' : null);
    }

    private function insuranceJustification(): Textarea
    {
        return Textarea::make('justificativa_seguro')
            ->label('Cliente com seguro obrigatório: justifique a não contratação')
            ->required()
            ->visible(fn (Get $get) => filled($get('insurance')) && app(CreateBooking::class)->needsInsuranceJustification(
                ['insurance' => $get('insurance'), 'cliente_id' => $get('cliente_id')],
                $this->operation,
                $this->mode,
                $this->booking,
            ))
            ->columnSpanFull();
    }

    private function client(): Section
    {
        $broker = fn (string $name, string $label, string $table, string $link) => Select::make($name)
            ->label($label)
            ->options(fn (Get $get) => DB::table($table)
                ->whereIn("{$table}_id", DB::table($link)->where('pessoa_id', $get('cliente_id'))->select("{$table}_id"))
                ->orWhere("{$table}_id", $this->current("{$table}_id"))
                ->orderBy('nome')
                ->pluck('nome', "{$table}_id"));

        return Section::make('Informações do cliente')
            ->columns(2)
            ->schema([
                ...$this->partyFields('cliente', 'Cliente'),
                $broker('assessoria_id', 'Assessoria', 'assessoria', 'pessoa_assessoria'),
                $broker('trading_id', 'Trading', 'trading', 'pessoa_trading')
                    ->live(),
                Select::make('despachante_id')
                    ->label('Despachante cliente')
                    ->options(fn (Get $get) => DB::table('despachante')
                        ->where(fn (Builder $q) => $q
                            ->whereIn('despachante_id', DB::table('pessoa_despachante')->whereIn('pessoa_id', array_filter([$get('cliente_id'), $get('pessoa_id_importador')]))->select('despachante_id'))
                            ->orWhereIn('despachante_id', DB::table('trading_despachante')->where('trading_id', $get('trading_id'))->select('despachante_id'))
                            ->orWhere('despachante_id', $this->current('despachante_id')))
                        ->orderBy('nome')
                        ->pluck('nome', 'despachante_id')),
                ...($this->isMaritimeExport() ? [] : [
                    Select::make('despachante_dmf_id')
                        ->label('Despachante DMF')
                        ->helperText('É o despachante padrão do porto de destino.')
                        ->disabled()
                        ->dehydrated()
                        ->options(fn () => DB::table('despachante')->orderBy('nome')->pluck('nome', 'despachante_id')),
                ]),
                TextInput::make('conta_cliente')
                    ->label('Conta cliente'),
            ]);
    }

    private function party(string $party): Section
    {
        $label = BookingParties::PARTIES[$party][2];

        return Section::make("Informações do {$label}")
            ->columns(2)
            ->schema($this->partyFields($party, $label));
    }

    /** Empresa + contatos. Ao escolher a empresa entram os contatos padrão dela, e os avisos aparecem abaixo do campo. */
    private function partyFields(string $party, string $label): array
    {
        $column = BookingParties::PARTIES[$party][0];
        // O legado consulta o CNPJ do cliente e do consignee na importação; na exportação, do cliente e do shipper.
        $checkCnpj = in_array($party, ['cliente', $this->isMaritimeExport() ? 'exportador' : 'importador'], true);

        return [
            ReportFields::person($column, $label)
                ->required($party === 'cliente')
                ->live()
                ->afterStateUpdated(fn ($state, Set $set) => $set("contacts.{$party}", app(BookingParties::class)->defaultContacts($state, $this->operation)))
                ->helperText(fn ($state) => $this->warningList(app(BookingParties::class)->warnings($state, $checkCnpj, $party === 'cliente'))),
            Select::make("contacts.{$party}")
                ->label('Contatos')
                ->multiple()
                ->options(fn (Get $get) => app(BookingParties::class)->contactOptions($get($column), $this->operation, $this->booking, $party)),
        ];
    }

    private function goods(): Section
    {
        $import = $this->operation === 'i';
        $maritime = $this->mode === 'm';
        $measures = $this->mode === 'a' || ! $import;
        $isNew = fn (Get $get): bool => blank($get('mercadoria_id'));
        $number = fn (string $name, string $label) => TextInput::make($name)
            ->label($label)
            ->numeric();

        return Section::make('Mercadoria')
            ->schema([
                Repeater::make('goods')
                    ->hiddenLabel()
                    ->addActionLabel('Adicionar mercadoria')
                    ->defaultItems(0)
                    ->columns(4)
                    // As exigências de preenchimento valem para a mercadoria nova: a edição não obriga a completar as antigas.
                    ->schema([
                        Hidden::make('mercadoria_id'),
                        TextInput::make('nome')
                            ->label('Mercadoria')
                            ->required($isNew),
                        TextInput::make('quantidade')
                            ->label('Quantidade')
                            ->required($isNew)
                            ->rule('integer', $isNew),
                        Select::make('tipo_container_id')
                            ->label('Equipamento')
                            ->required($isNew)
                            ->live()
                            ->options(fn (Get $get) => DB::table('tipo_container')
                                ->where('tipo_embarque', $this->mode)
                                ->when(! $isNew($get), fn (Builder $q) => $q->orWhere('tipo_container_id', (int) $get('tipo_container_id')))
                                ->orderBy('nome')
                                ->pluck('nome', 'tipo_container_id')),
                        Select::make('temperatura')
                            ->label('Temperatura')
                            ->required($isNew)
                            ->visible(fn (Get $get) => $maritime && Container::isReefer($get('tipo_container_id')))
                            ->options(collect(range(25, -25))->mapWithKeys(fn (int $t) => [$t => "{$t}°"])->all()),
                        ...($measures ? [
                            $number('gross_weight', 'Gross weight (kg)'),
                            $number('net_weight', $maritime ? 'Net weight (kg)' : 'Chargeable weight (kg)'),
                            $number('cubature', 'Peso volumétrico (m³)'),
                            $number('width', 'Width (cm)'),
                            $number('height', 'Height (cm)'),
                            $number('depth', 'Depth (cm)'),
                        ] : []),
                        TextInput::make('free_time')
                            ->label('Free time')
                            ->integer(),
                        Select::make('quimico')
                            ->label('Químico?')
                            ->required(fn (Get $get) => $import && $isNew($get))
                            ->live()
                            ->options([0 => 'NÃO', 1 => 'SIM']),
                        Select::make('carga_perigosa')
                            ->label('Carga perigosa (IMO)?')
                            ->required(fn (Get $get) => $import && $isNew($get))
                            ->visible(fn (Get $get) => (string) $get('quimico') === '1')
                            ->options([0 => 'NÃO', 1 => 'SIM']),
                        ...($maritime ? [
                            Select::make('super_testado')
                                ->label('Super testado?')
                                ->options([0 => 'NÃO', 1 => 'SIM'])
                                ->default(0)
                                ->selectablePlaceholder(false),
                        ] : []),
                    ]),
            ]);
    }

    /** "Informações gerais" da exportação marítima: deadlines, locais de retirada e entrega e comentários. */
    private function deadlines(): Section
    {
        $dateTime = fn (string $name, string $label) => DateTimePicker::make($name)
            ->label($label)
            ->seconds(false);

        return Section::make('Informações gerais')
            ->columns(3)
            ->schema([
                $dateTime('dead_line_draft_armador', 'Dead line Draft'),
                $dateTime('dead_line_vgm', 'Dead line VGM'),
                $dateTime('dead_line_carga_time', 'Dead line Carga'),
                $this->date('dead_line_carta_temperatura', 'Dead line carta temperatura'),
                $dateTime('dead_line_dde', 'Dead line DDE liberado'),
                TextInput::make('lista_embarque')
                    ->label('Dead line IMO (texto)'),
                $dateTime('dead_line_imo', 'Dead line IMO'),
                $this->date('prontidao', 'Prontidão'),
                $dateTime('deadline_draft_armador', 'Dead line Draft armador'),
                $this->date('deadline_carga_armador', 'Dead line Draft carga armador'),
                $this->note('local_retirada', 'Local de retirada dos containers'),
                $this->note('local_entrega', 'Local de entrega dos containers'),
                $this->note('comentarios', 'Comentários'),
            ]);
    }
}
