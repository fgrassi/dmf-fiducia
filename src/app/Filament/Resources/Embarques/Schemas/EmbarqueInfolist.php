<?php

namespace App\Filament\Resources\Embarques\Schemas;

use App\Filament\Support\OperationBadge;
use App\Models\Cidade;
use App\Models\Container;
use App\Models\Embarque;
use App\Models\Estado;
use App\Models\FinExpensesValues;
use App\Models\Navio;
use App\Models\Pais;
use Carbon\Carbon;
use Closure;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconSize;
use Illuminate\Support\Facades\DB;

class EmbarqueInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Grid::make(3)
                    ->columnSpanFull()
                    ->schema([
                        Section::make('Reserva')
                            ->columnSpan(fn (Embarque $record) => ($record->impor_expor === 'e' && $record->tipo_embarque !== 'a') ? 2 : 3)
                            ->extraAttributes(['style' => 'height: 100%; display: grid'])
                            ->columns(3)
                            ->schema([
                                Group::make([
                                    TextEntry::make('vendedor.nome')
                                        ->label('Vendedor')
                                        ->placeholder('Não informado'),
                                    TextEntry::make('finalizado')
                                        ->label('Situação')
                                        ->badge()
                                        ->formatStateUsing(fn (?string $state) => Embarque::STATUSES[$state] ?? $state),
                                    OperationBadge::entry()
                                        ->label('Tipo de reserva'),
                                    TextEntry::make('data_topo')
                                        ->label('Data de solicitação')
                                        ->date('d/m/Y')
                                        ->placeholder('Não informada'),
                                    TextEntry::make('agente.nome')
                                        ->label('Agente')
                                        ->placeholder('Não informado'),
                                    TextEntry::make('escritorio.nome')
                                        ->label('Escritório do agente')
                                        ->state(fn (Embarque $record) => $record->escritorio
                                            ? collect([$record->escritorio->nome, $record->escritorio->nif ? "NIF: {$record->escritorio->nif}" : null, $record->escritorio->observacoes ? "Endereço: {$record->escritorio->observacoes}" : null])->filter()->implode("\n")
                                            : null)
                                        ->extraAttributes(['class' => 'whitespace-pre-line'])
                                        ->placeholder('Não informado')
                                        ->visible(fn (Embarque $record) => $record->impor_expor !== 'e' && $record->tipo_embarque === 'm'),
                                    TextEntry::make('bl_direto')
                                        ->label('BL direto')
                                        ->formatStateUsing(fn ($state) => $state ? 'SIM' : 'NÃO')
                                        ->visible(fn (Embarque $record) => $record->impor_expor === 'e' && $record->tipo_embarque === 'm'),
                                    TextEntry::make('tipo_fechamento')
                                        ->label('Tipo de fechamento')
                                        ->formatStateUsing(fn (?string $state) => ['o' => 'Origem / DMF', 'd' => 'Destino / Agente'][$state] ?? null)
                                        ->placeholder('N/D')
                                        ->visible(fn (Embarque $record) => $record->impor_expor === 'e' && $record->tipo_embarque === 'm'),
                                    TextEntry::make('autor.nome')
                                        ->label('Criado por')
                                        ->placeholder('Não informado'),
                                    TextEntry::make('de')
                                        ->label('Responsável')
                                        ->placeholder('Não informado')
                                        ->visible(fn (Embarque $record) => ! self::isMaritimeExport($record)),
                                    TextEntry::make('de_fone')
                                        ->label('Fone')
                                        ->placeholder('Não informado')
                                        ->visible(fn (Embarque $record) => ! self::isMaritimeExport($record)),
                                    self::multiline('observacaoint', 'Observação'),
                                ]),
                                Group::make([
                                    TextEntry::make('booking')
                                        ->label('Reserva'),
                                    TextEntry::make('invoice')
                                        ->label('Invoice')
                                        ->placeholder('Não informado'),
                                    TextEntry::make('customer_ref')
                                        ->label('Customer reference')
                                        ->placeholder('Não informado'),
                                    TextEntry::make('house_bl')
                                        ->label('HBL')
                                        ->placeholder('Não informado'),
                                    TextEntry::make('qtd_bl')
                                        ->label('Quantidade HBL')
                                        ->placeholder('Não informada')
                                        ->visible(fn (Embarque $record) => $record->tipo_embarque === 'm'),
                                    TextEntry::make('master_bl')
                                        ->label('MBL')
                                        ->placeholder('Não informado'),
                                    TextEntry::make('nr_container')
                                        ->label('Nr. do(s) container(s)')
                                        ->extraAttributes(['class' => 'whitespace-pre-line'])
                                        ->placeholder('Não informado')
                                        ->visible(fn (Embarque $record) => $record->impor_expor !== 'e' && $record->tipo_embarque === 'm'),
                                    TextEntry::make('ce_mercante')
                                        ->label('CE Mercante')
                                        ->placeholder('Não informado')
                                        ->visible(fn (Embarque $record) => $record->impor_expor !== 'e' && $record->tipo_embarque === 'm'),
                                    self::date('data_validade', 'Validade da cotação')
                                        ->visible(fn (Embarque $record) => $record->tipo_embarque === 'm'),
                                    TextEntry::make('consolidado')
                                        ->label('Consolidado')
                                        ->formatStateUsing(fn (?string $state) => ['n' => 'NÃO', 's' => 'SIM'][$state] ?? null)
                                        ->placeholder('N/D')
                                        ->visible(fn (Embarque $record) => $record->tipo_embarque === 'm'),
                                    TextEntry::make('tipo_frete')
                                        ->label('Local de pagamento do frete')
                                        ->formatStateUsing(fn (?string $state) => ['p' => 'PP', 'c' => 'CC', 'a' => 'ABROAD'][$state] ?? null)
                                        ->placeholder('N/D')
                                        ->visible(fn (Embarque $record) => $record->tipo_embarque === 'm'),
                                    TextEntry::make('controle_imo')
                                        ->label('Controle IMO')
                                        ->formatStateUsing(fn (?string $state) => $state === 's' ? 'OK' : null)
                                        ->placeholder('-')
                                        ->visible(fn (Embarque $record) => $record->impor_expor !== 'e' && $record->tipo_embarque === 'm'),
                                ]),
                                Group::make([
                                    TextEntry::make('de')
                                        ->label('Responsável pelo fechamento')
                                        ->placeholder('Não informado')
                                        ->visible(fn (Embarque $record) => self::isMaritimeExport($record)),
                                    TextEntry::make('tipo_embarque')
                                        ->label('Modal')
                                        ->formatStateUsing(fn (?string $state) => Embarque::TYPES[$state] ?? $state),
                                    TextEntry::make('cliente.nome')
                                        ->label('Cliente')
                                        ->placeholder('Não informado'),
                                    TextEntry::make('importador.nome')
                                        ->label('Importador')
                                        ->placeholder('Não informado'),
                                    TextEntry::make('ctr_desp')
                                        ->label('Despesas pendentes de cobrança')
                                        ->formatStateUsing(fn ($state) => (int) $state === 1 ? 'Sim' : 'Não'),
                                ]),
                            ]),
                        Group::make([
                            Section::make('Status do embarque')
                                ->columns(2)
                                ->compact()
                                ->schema(fn (Embarque $record) => collect($record->shipmentStatus())
                                    ->map(fn (bool $done, string $label) => IconEntry::make("status_{$label}")
                                        ->label($label)
                                        ->state($done)
                                        ->size(IconSize::Small)
                                        ->boolean())
                                    ->values()
                                    ->all()),
                            Section::make('Consignar MBL para')
                                ->columns(1)
                                ->compact()
                                ->schema([
                                    TextEntry::make('agente.consignar_mbl')
                                        ->label('Observações do agente')
                                        ->extraAttributes(['class' => 'whitespace-pre-line'])
                                        ->placeholder('Não informado'),
                                    TextEntry::make('observacoes_pais')
                                        ->label('Observações do país')
                                        ->state(fn (Embarque $record) => Pais::find($record->agente?->paises_id)?->observacao)
                                        ->extraAttributes(['class' => 'whitespace-pre-line'])
                                        ->placeholder('Não informadas'),
                                ]),
                        ])
                            ->columnSpan(1)
                            ->visible(fn (Embarque $record) => $record->impor_expor === 'e' && $record->tipo_embarque !== 'a'),
                    ]),
                self::insurance(),
                Section::make('Embarque')
                    ->columnSpanFull()
                    ->columns(4)
                    ->schema(fn (Embarque $record) => self::shipment($record)),
                Group::make(fn (Embarque $record) => self::parties($record))
                    ->columnSpanFull(),
                Section::make('Mercadoria')
                    ->columnSpanFull()
                    ->schema([
                        self::goods('goods_air', ['nome', 'quantidade', 'equipment', 'gross_weight', 'net_weight', 'cubature', 'width', 'height', 'depth'])
                            ->visible(fn (Embarque $record) => $record->tipo_embarque === 'a'),
                        self::goods('goods_export', ['nome', 'quantidade', 'equipment', 'free_time', 'gross_weight', 'net_weight', 'cubature', 'width', 'height', 'depth', 'quimico', 'carga_perigosa', 'super_testado', 'temperatura'])
                            ->visible(fn (Embarque $record) => $record->tipo_embarque !== 'a' && $record->impor_expor === 'e'),
                        self::goods('goods_import', ['nome', 'quantidade', 'equipment', 'free_time', 'quimico', 'carga_perigosa'])
                            ->visible(fn (Embarque $record) => $record->tipo_embarque !== 'a' && $record->impor_expor !== 'e'),
                    ]),
                Section::make('Informações gerais')
                    ->columnSpanFull()
                    ->columns(2)
                    ->visible(fn (Embarque $record) => $record->impor_expor === 'e' && $record->tipo_embarque !== 'a')
                    ->schema([
                        Group::make([
                            self::deadline('dead_line_draft_armador', 'Dead line Draft'),
                            TextEntry::make('dead_line_draft')
                                ->label('Obs. dead line Draft')
                                ->placeholder('-'),
                            TextEntry::make('controle_confirmacao')
                                ->label('Controle prontidão')
                                ->formatStateUsing(fn ($state) => (int) $state === 1 ? 'SIM' : 'NÃO'),
                            self::deadline('dead_line_vgm', 'Dead line VGM'),
                            self::deadline('dead_line_carga_time', 'Dead line Carga'),
                            TextEntry::make('dead_line_carga')
                                ->label('Obs. dead line Carga')
                                ->placeholder('-'),
                            self::deadline('dead_line_carta_temperatura', 'Dead line Carta Temperatura', false),
                            self::deadline('dead_line_dde', 'Dead line DDE Liberada'),
                            // o legado mostra o texto da lista de embarque no lugar da data, quando preenchido
                            TextEntry::make('dead_line_imo')
                                ->label('Dead line IMO')
                                ->state(fn (Embarque $record) => filled($record->lista_embarque)
                                    ? $record->lista_embarque
                                    : ($record->dead_line_imo ? Carbon::parse($record->dead_line_imo)->format('d/m/Y H:i') : null))
                                ->placeholder('N/D'),
                            self::deadline('prontidao', 'Prontidão', false),
                        ]),
                        Group::make([
                            self::deadline('deadline_draft_armador', 'Dead line Draft Armador'),
                            self::deadline('deadline_carga_armador', 'Dead line Carga Armador'),
                            self::multiline('local_retirada', 'Local de retirada dos containers'),
                            self::multiline('local_entrega', 'Local de entrega dos containers'),
                            self::multiline('comentarios', 'Comentários'),
                        ]),
                    ]),
            ]);
    }

    /** Data do legado: '0000-00-00' e vazio viram "sem valor". */
    /**
     * Informações do EMBARQUE: cada modal tem a sua ordem no legado (showExpo, showImp, showAereo), em duas colunas.
     *
     * @return list<Group>
     */
    private static function shipment(Embarque $record): array
    {
        $export = $record->impor_expor === 'e';

        [$left, $right] = match (true) {
            $record->tipo_embarque === 'a' => [
                [...($export ? ['etd_inicial', 'eta_inicial'] : []), 'saida_prevista', 'chegada_prevista', 'transit', 'saida_efetiva', 'chegada_efetiva', 'transit_efetivo', 'incoterm', 'embarque_obs'],
                ['portoOrigem', 'portoDestino', 'via' => 'Rota', 'cia_aerea', 'peso_cotacao', 'volume_cotacao', 'fator_wm'],
            ],
            $export => [
                ['navio', 'navioDestino', 'vg', 'etd_inicial', 'eta_inicial', 'transit_inicial', 'saida_prevista', 'chegada_prevista', 'transit', 'data_entrega_agente', 'saida_efetiva', 'chegada_efetiva', 'transit_efetivo', 'incoterm', 'fretes', 'embarque_obs', 'flag_fi', 'pagador', 'recebedor'],
                ['place_of_receipt', 'portoOrigem' => 'Porto de embarque', 'via' => 'Transbordo', 'portoDestino' => 'Porto de descarga', 'place_of_delivery', 'destino_final', 'inland', 'free_time_detention', 'free_time' => 'Free time (destino)', 'obs_free_time_destino', 'armador', 'type_of_service'],
            ],
            default => [
                ['navio', 'navioDestino', 'vg', 'saida_prevista', 'chegada_prevista', 'chegada_desejada', 'transit', 'data_entrega_agente', 'saida_efetiva', 'chegada_efetiva', 'transit_efetivo', 'loading_type', 'armador', 'coloader', 'free_time', 'incoterm', 'embarque_obs', 'data_entrega_container_origem', 'flag_fi', 'pagador', 'recebedor'],
                ['portoOrigem', 'portoDestino', 'via', 'garantee', 'insurance', 'demurrage', 'demurrage_days', 'redestinacao', 'autorizacao', 'etd_inicial', 'eta_inicial', 'transit_inicial'],
            ],
        };

        // as duas colunas do legado, cada uma partida ao meio, viram 4 colunas na mesma ordem de leitura
        return array_map(
            fn (array $keys) => self::shipmentGroup($keys),
            [...array_chunk($left, (int) ceil(count($left) / 2), true), ...array_chunk($right, (int) ceil(count($right) / 2), true)],
        );
    }

    /** @param  array<int|string, string>  $keys  "chave" ou "chave" => "rótulo do legado" */
    private static function shipmentGroup(array $keys): Group
    {
        $entries = [];

        foreach ($keys as $key => $label) {
            $entry = self::shipmentEntry(is_int($key) ? $label : $key);
            $entries[] = is_int($key) ? $entry : $entry->label($label);
        }

        return Group::make($entries);
    }

    private static function shipmentEntry(string $key): TextEntry
    {
        $text = fn (string $field, string $label, string $placeholder = 'Não informado') => TextEntry::make($field)->label($label)->placeholder($placeholder);
        $computed = fn (string $name, string $label, Closure $state, string $placeholder = '-') => TextEntry::make($name)->label($label)->state($state)->placeholder($placeholder);
        $lookup = fn (string $name, string $label, array $options) => TextEntry::make($name)->label($label)->state(fn (Embarque $record) => $options[$record->{$name}] ?? null)->placeholder('-');
        $multiline = fn (string $field, string $label) => self::multiline($field, $label);
        $invoiceOnly = fn (TextEntry $entry) => $entry->visible(fn (Embarque $record) => (int) $record->flag_fi === 1);

        return match ($key) {
            'navio' => $computed('navio', 'Navio', fn (Embarque $record) => self::ship($record->navio), 'Não informado'),
            'navioDestino' => $computed('navioDestino', 'Navio destino', fn (Embarque $record) => self::ship($record->navioDestino), 'Não informado'),
            'vg' => $text('vg', 'VG'),
            'etd_inicial' => self::date('etd_inicial', 'ETD inicial'),
            'eta_inicial' => self::date('eta_inicial', 'ETA inicial'),
            'transit_inicial' => $computed('transit_inicial', 'Transit time inicial', fn (Embarque $record) => self::days($record->etd_inicial, $record->eta_inicial)),
            'saida_prevista' => self::date('saida_prevista', 'ETD'),
            'chegada_prevista' => self::date('chegada_prevista', 'ETA'),
            'chegada_desejada' => self::date('chegada_desejada', 'ETA desejado')->visible(fn (Embarque $record) => (int) $record->flag_prioritario === 1),
            'transit' => $computed('transit', 'Transit time', fn (Embarque $record) => self::days($record->saida_prevista, $record->chegada_prevista)),
            'data_entrega_agente' => self::date('data_entrega_agente', 'Data de entrega ao agente'),
            'saida_efetiva' => self::date('saida_efetiva', 'Data de saída efetiva'),
            'chegada_efetiva' => self::date('chegada_efetiva', 'Data de chegada efetiva'),
            'transit_efetivo' => $computed('transit_efetivo', 'Transit time efetivo', fn (Embarque $record) => self::days($record->saida_efetiva, $record->chegada_efetiva)),
            'loading_type' => $lookup('loading_type', 'Tipo de embarque', ['f' => 'Free Hand', 'd' => 'DMF']),
            'armador' => $text('armador.nome', 'Armador'),
            'coloader' => $text('coloader.nome', 'Coloader'),
            'free_time' => $text('free_time', 'Free time', '-'),
            'incoterm' => $computed('incoterm', 'Incoterm do embarque', fn (Embarque $record) => $record->incoterm_id ? DB::table('incoterm')->where('incoterm_id', $record->incoterm_id)->value('nome') : null, 'Não informado'),
            'fretes' => $computed('fretes', 'Fretes negociados', fn (Embarque $record) => self::negotiatedFreights($record))->listWithLineBreaks(),
            'embarque_obs' => $multiline('embarque_obs', 'Obs. do embarque'),
            'data_entrega_container_origem' => self::date('data_entrega_container_origem', 'Entrega do container na origem'),
            'flag_fi' => TextEntry::make('flag_fi')->label('Freight Invoice?')->formatStateUsing(fn ($state) => (int) $state === 0 ? 'Não' : 'Sim'),
            'pagador' => $invoiceOnly($text('pagador.nome', 'Pagador')),
            'recebedor' => $invoiceOnly(TextEntry::make('recebedor_freight_invoice')->label('Recebedor')->formatStateUsing(fn (?string $state) => ['d' => 'DMF INTERNACIONAL', 'a' => 'AIC', 'j' => 'JADE'][$state] ?? $state)->placeholder('Não informado')),
            'portoOrigem' => $text('portoOrigem.label', 'Origem'),
            'portoDestino' => $text('portoDestino.label', 'Destino'),
            'via' => $text('via', 'Via'),
            'cia_aerea' => $computed('cia_aerea', 'Cia aérea', fn (Embarque $record) => $record->ciaAerea?->name, 'Não informada'),
            'peso_cotacao' => TextEntry::make('peso_cotacao')->label('Peso cotação')->numeric(4, ',', '.'),
            'volume_cotacao' => TextEntry::make('volume_cotacao')->label('Volume cotação')->numeric(4, ',', '.'),
            'fator_wm' => TextEntry::make('fator_wm')->label('Fator WM')->state(fn (Embarque $record) => max((float) $record->volume_cotacao / 0.006, (float) $record->peso_cotacao))->numeric(4, ',', '.'),
            'garantee' => $lookup('garantee', 'Security deposit', ['n' => 'NO', 's' => 'YES', '3' => '30 DAYS', '4' => '45 DAYS', '5' => '50 DAYS', '6' => '60 DAYS', '9' => '90 DAYS']),
            'insurance' => $lookup('insurance', 'Seguro contratado', ['s' => 'SIM', 'n' => 'NÃO']),
            'demurrage' => $lookup('demurrage', 'Gerou demurrage', ['s' => 'SIM', 'n' => 'NÃO']),
            'demurrage_days' => $text('demurrage_days', 'Dias de demurrage', '-'),
            'redestinacao' => $lookup('ctr_redestinacao', 'Redestinação', [1 => 'Pendente', 2 => 'OK']),
            'autorizacao' => $lookup('ctrl_autorizacao_impo', 'Autorização', [1 => 'SIM', 2 => 'NÃO']),
            'place_of_receipt' => $multiline('place_of_receipt', 'Place of receipt'),
            // informação antiga: só aparece quando preenchida
            'place_of_delivery' => $multiline('place_of_delivery', 'Final destination*')->visible(fn (Embarque $record) => filled($record->place_of_delivery)),
            'destino_final' => $computed('destino_final', 'Final destination', fn (Embarque $record) => self::finalDestination($record), 'Nenhum endereço selecionado')->extraAttributes(['class' => 'whitespace-pre-line']),
            'inland' => $lookup('inland', 'Inland', [1 => 'Armador', 2 => 'Agente']),
            'free_time_detention' => $text('free_time_detention', 'Free time detention (origem)', '-'),
            'obs_free_time_destino' => $multiline('obs_free_time_destino', 'Obs. free time (destino)'),
            'type_of_service' => $lookup('type_of_service', 'Type of service', [
                'aa' => 'AIR TO AIR', 'ad' => 'AIR TO DOOR', 'da' => 'DOOR TO AIRPORT', 'dd' => 'DOOR TO DOOR', 'dp' => 'DOOR/PORT', 'pd' => 'PORT/DOOR',
                'pp' => 'PORT/PORT', 'rd' => 'RAIL/DOOR', 'rp' => 'RAIL/PORT', 'te' => 'AIR FREIGHT', 'tt' => 'TRUCK/TRUCK',
            ]),
        };
    }

    /** Embarque::getDespesasFrete (legado): despesas de frete da reserva com a forma de cobrança ("Despesa/Un (equip) (BL) container - CC"). */
    private static function negotiatedFreights(Embarque $record): array
    {
        $ids = DB::table('regra_despesa_cobranca_item')->where('regra_despesa_cobranca_id', 4)->pluck('despesa_id')->push(703, 1612, 1613);

        return $record->expenses()
            ->whereIn('ref_expense', $ids)
            ->with(['despesa.unity', 'equipment', 'bl'])
            ->get()
            ->map(fn (FinExpensesValues $expense) => $expense->despesa?->label
                .($expense->equipment ? " ({$expense->equipment->nome})" : '')
                .($expense->bl ? " ({$expense->bl->bl})" : '')
                .($expense->ref_container ? ' '.Container::find($expense->ref_container)?->numero : '')
                .' - '.(['a' => 'PA', 'c' => 'CC', 'p' => 'PP', 's' => 'SEG', 'f' => 'FI'][$expense->billing] ?? ''))
            ->all();
    }

    /** Endereço do Final Destination (destino_final) em linhas, ou null quando não há. */
    private static function finalDestination(Embarque $record): ?string
    {
        $destination = DB::table('destino_final')->where('destino_final_id', $record->destino_final_id)->first();

        if (! $destination) {
            return null;
        }

        return implode("\n", [
            "Endereço: {$destination->endereco}",
            "Zip Code: {$destination->zip_code}",
            'País: '.Pais::find($destination->pais_id)?->nome,
            'Estado: '.Estado::find($destination->estado_id)?->nome,
            'Cidade: '.Cidade::find($destination->cidade_id)?->nome,
        ]);
    }

    /** Reserva de exportação marítima: o legado mostra "Responsável pelo fechamento" e não mostra o fone. */
    private static function isMaritimeExport(Embarque $record): bool
    {
        return $record->impor_expor === 'e' && $record->tipo_embarque !== 'a';
    }

    private static function date(string $field, string $label): TextEntry
    {
        return TextEntry::make($field)
            ->label($label)
            ->state(fn (Embarque $record) => filled($record->{$field}) && ! str_starts_with((string) $record->{$field}, '0000') ? $record->{$field} : null)
            ->date('d/m/Y')
            ->placeholder('Não informada');
    }

    /** Dias entre duas datas do legado (transit time), ou null se faltar uma delas. */
    private static function days(?string $from, ?string $to): ?int
    {
        $valid = fn (?string $date) => filled($date) && ! str_starts_with($date, '0000');

        return $valid($from) && $valid($to) ? Carbon::parse($from)->startOfDay()->diffInDays(Carbon::parse($to)->startOfDay(), true) : null;
    }

    /** "Nome (N metros)" como o legado (Embarque::getInfoNavio). */
    private static function ship(?Navio $ship): ?string
    {
        return $ship ? $ship->nome.($ship->tamanho > 0 ? " ({$ship->tamanho} metros)" : '') : null;
    }

    private static function deadline(string $field, string $label, bool $withTime = true): TextEntry
    {
        return TextEntry::make($field)
            ->label($label)
            ->dateTime($withTime ? 'd/m/Y H:i' : 'd/m/Y')
            ->placeholder('N/D');
    }

    private static function multiline(string $field, string $label): TextEntry
    {
        return TextEntry::make($field)
            ->label($label)
            ->extraAttributes(['class' => 'whitespace-pre-line'])
            ->placeholder('-');
    }

    /**
     * Mercadorias da reserva, só com as colunas da tela do legado (showAereo / showExpo / showImp).
     *
     * @param  list<string>  $fields
     */
    private static function goods(string $name, array $fields): RepeatableEntry
    {
        $triState = fn (?int $state) => match ((int) $state) {
            0 => 'Não',
            1 => 'Sim',
            default => '-',
        };
        $weight = fn (string $field, string $label) => TextEntry::make($field)
            ->label($label)
            ->numeric(4, ',', '.')
            ->placeholder('-');

        $entries = [
            'nome' => TextEntry::make('nome')->label('Mercadoria')->placeholder('Não informada'),
            'quantidade' => TextEntry::make('quantidade')->label('Quantidade'),
            'equipment' => TextEntry::make('equipment.nome')->label('Equipamento')->placeholder('Não informado'),
            'free_time' => TextEntry::make('free_time')->label('Free time')->placeholder('-'),
            'gross_weight' => $weight('gross_weight', 'Gross weight'),
            'net_weight' => $weight('net_weight', $name === 'goods_air' ? 'Chargeable weight' : 'Net weight'),
            'cubature' => $weight('cubature', 'Peso volumétrico'),
            'width' => $weight('width', 'Width'),
            'height' => $weight('height', 'Height'),
            'depth' => $weight('depth', 'Depth'),
            'quimico' => TextEntry::make('quimico')->label('Químico')->formatStateUsing($triState),
            'carga_perigosa' => TextEntry::make('carga_perigosa')->label('IMO')->formatStateUsing($triState),
            'super_testado' => TextEntry::make('super_testado')->label('Super testado?')->formatStateUsing(fn ($state) => $state ? 'Sim' : 'Não'),
            // temperatura só vale para os equipamentos reefer (ids do legado)
            'temperatura' => TextEntry::make('temperatura')
                ->label('Temperatura')
                ->state(fn ($record) => in_array((int) $record->tipo_container_id, [8, 10, 19, 39], true) ? $record->temperatura : null)
                ->placeholder('-'),
        ];

        return RepeatableEntry::make($name)
            ->state(fn (Embarque $record) => $record->goods)
            ->hiddenLabel()
            ->placeholder('Nenhuma mercadoria adicionada')
            ->columns(4)
            ->schema(array_map(fn (string $field) => $entries[$field], $fields));
    }

    /**
     * Dados das partes, na ordem de cada modal do legado: exportação (cliente, exportador, importador),
     * importação marítima (cliente, CNEE, notify, shipper) e aéreo (cliente, importador, notify, exportador).
     *
     * @return list<Section>
     */
    private static function parties(Embarque $record): array
    {
        $air = $record->tipo_embarque === 'a';
        $contacts = fn (string $role) => $air ? 'Contatos' : "Contatos do {$role}";
        $client = self::client($record, $air);
        $importer = self::party('Dados do importador', 'Importador', 'importador', 'importerContacts', $contacts('Importador'));
        $notify = self::party('Dados do notify', 'Notify', 'notify', 'notifyContacts', $contacts('Notify'));
        $exporter = self::party('Dados do exportador', 'Exportador', 'exportador', 'shipperContacts', $contacts('Exportador'));

        return match (true) {
            $air => [$client, $importer, $notify, $exporter],
            $record->impor_expor === 'e' => [$client, $exporter, $importer],
            default => [
                $client,
                self::party('Dados do CNEE', 'CNEE', 'importador', 'importerContacts', 'Contatos do Cnee'),
                $notify,
                self::party('Dados do shipper', 'Shipper', 'exportador', 'shipperContacts', 'Contatos do Shipper'),
            ],
        };
    }

    /** Seguro (seguro/show do legado): seguradora, valores da cotação e se a cobrança já foi habilitada. Só nas reservas de seguro. */
    private static function insurance(): Section
    {
        $amount = fn (string $key, string $currency, string $label) => TextEntry::make("insurance_{$key}")
            ->label($label)
            ->state(function (Embarque $record) use ($key, $currency) {
                $insurance = $record->insuranceConfirmation;

                return $insurance ? trim("{$insurance->{$currency}?->symbol} ".number_format((float) $insurance->{$key}, 2, ',', '.')) : null;
            })
            ->placeholder('-');

        return Section::make('Seguro')
            ->columnSpanFull()
            ->columns(4)
            ->visible(fn (Embarque $record) => $record->tipo_embarque === 's')
            ->schema([
                TextEntry::make('insurance_company')
                    ->label('Cia Seguro')
                    ->state(fn (Embarque $record) => $record->insuranceConfirmation?->ciaSeguro?->name)
                    ->placeholder('-'),
                $amount('valor_fatura', 'invoiceCurrency', 'Valor da Fatura'),
                $amount('valor_frete', 'freightCurrency', 'Valor do Frete'),
                $amount('despesas', 'expensesCurrency', 'Despesas (10%)'),
                TextEntry::make('insurance_charge')
                    ->label('Cobrança')
                    ->badge()
                    ->state(fn (Embarque $record) => $record->insuranceConfirmation?->isChargeAllowed() ? 'Habilitada' : 'Aguardando habilitação')
                    ->color(fn (string $state) => $state === 'Habilitada' ? 'success' : 'warning'),
            ]);
    }

    /** Dados do cliente: à direita assessoria, trading e despachantes (no aéreo, só os brokers; no marítimo, conta e contatos). */
    private static function client(Embarque $record, bool $air): Section
    {
        $import = ! $air && $record->impor_expor !== 'e';
        $name = fn (string $label, string $key, string $table, string $primary) => TextEntry::make($key)
            ->label($label)
            ->state(fn (Embarque $record) => $record->{$key} ? DB::table($table)->where($primary, $record->{$key})->value('nome') : null)
            ->placeholder('-');

        return Section::make('Dados do cliente')
            ->columnSpanFull()
            ->columns(2)
            ->schema([
                Group::make([
                    TextEntry::make('cliente.nome')
                        ->label('Cliente')
                        ->placeholder('Não informado'),
                    self::contacts('clientContacts', $air ? 'Contatos' : 'Contatos do Cliente'),
                ]),
                Group::make([
                    $name('Assessoria', 'assessoria_id', 'assessoria', 'assessoria_id'),
                    $name('Trading', 'trading_id', 'trading', 'trading_id'),
                    ...($air ? [] : [self::multiline('conta_cliente', 'Conta Cliente')]),
                    $name($air ? 'Customer Broker' : 'Despachante Cliente', 'despachante_id', 'despachante', 'despachante_id'),
                    ...($air ? [] : [self::brokerContacts()]),
                    ...($air || $import ? [$name($air ? 'DMF Broker' : 'Despachante DMF', 'despachante_dmf_id', 'despachante', 'despachante_id')] : []),
                ]),
            ]);
    }

    /** Contatos do despachante do cliente: ativos, desta operação e ligados ao cliente da reserva. */
    private static function brokerContacts(): TextEntry
    {
        return TextEntry::make('despachante_contatos')
            ->label('Contatos Despachante Cliente')
            ->state(fn (Embarque $record) => $record->despachante_id
                ? DB::table('despachante_contato as c')
                    ->join('pessoa_contato_despachante as p', 'p.despachante_contato_id', '=', 'c.id')
                    ->where('c.ref_despachante', $record->despachante_id)
                    ->where('p.pessoa_id', $record->cliente_id)
                    ->where('c.status', 1)
                    ->where('c.impor_expor', $record->impor_expor)
                    ->get(['c.name', 'c.email'])
                    ->map(fn ($contact) => "{$contact->name} ({$contact->email})")
                    ->all()
                : [])
            ->listWithLineBreaks()
            ->placeholder('Nenhum contato vinculado');
    }

    private static function party(string $title, string $label, string $relation, string $contacts, string $contactsLabel): Section
    {
        return Section::make($title)
            ->columns(2)
            ->schema([
                TextEntry::make("{$relation}.nome")
                    ->label($label)
                    ->placeholder('Não informado'),
                self::contacts($contacts, $contactsLabel),
            ]);
    }

    /** "Nome (e-mail)" por contato ativo da reserva, em ordem alfabética, um por linha. */
    private static function contacts(string $relation, string $label): TextEntry
    {
        return TextEntry::make($relation)
            ->label($label)
            ->state(fn (Embarque $record) => $record->{$relation}()
                ->where('usuario.status', 1)
                ->orderBy('usuario.nome')
                ->get()
                ->map(fn ($u) => trim("{$u->nome} ({$u->email})"))
                ->all())
            ->listWithLineBreaks()
            ->placeholder('Não informado');
    }
}
