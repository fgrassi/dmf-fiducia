<?php

namespace App\Filament\Resources\Embarques\Schemas;

use App\Filament\Support\OperationBadge;
use App\Models\Embarque;
use Carbon\Carbon;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EmbarqueInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Reserva')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('booking')
                            ->label('Reserva'),
                        TextEntry::make('vendedor.nome')
                            ->label('Vendedor')
                            ->placeholder('Não informado'),
                        OperationBadge::entry()
                            ->label('Tipo de reserva'),
                        TextEntry::make('tipo_embarque')
                            ->label('Modal')
                            ->formatStateUsing(fn (?string $state) => Embarque::TYPES[$state] ?? $state),
                        TextEntry::make('cliente.nome')
                            ->label('Cliente')
                            ->placeholder('Não informado'),
                        TextEntry::make('data_topo')
                            ->label('Data de solicitação')
                            ->date('d/m/Y')
                            ->placeholder('Não informada'),
                        TextEntry::make('agente.nome')
                            ->label('Agente')
                            ->placeholder('Não informado'),
                        TextEntry::make('importador.nome')
                            ->label('Importador')
                            ->placeholder('Não informado'),
                        TextEntry::make('armador.nome')
                            ->label('Armador')
                            ->placeholder('Não informado'),
                        TextEntry::make('house_bl')
                            ->label('HBL')
                            ->placeholder('Não informado'),
                        TextEntry::make('master_bl')
                            ->label('MBL')
                            ->placeholder('Não informado'),
                        TextEntry::make('invoice')
                            ->label('Invoice')
                            ->placeholder('Não informado'),
                        TextEntry::make('saida_prevista')
                            ->label('Saída prevista')
                            ->date('d/m/Y')
                            ->placeholder('Não informada'),
                        TextEntry::make('chegada_prevista')
                            ->label('Chegada prevista')
                            ->date('d/m/Y')
                            ->placeholder('Não informada'),
                        TextEntry::make('finalizado')
                            ->label('Situação')
                            ->badge()
                            ->formatStateUsing(fn (?string $state) => Embarque::STATUSES[$state] ?? $state),
                        TextEntry::make('ctr_desp')
                            ->label('Despesas pendentes de cobrança')
                            ->formatStateUsing(fn ($state) => (int) $state === 1 ? 'Sim' : 'Não'),
                    ]),
                Section::make('Dados do cliente')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('cliente.nome')
                            ->label('Nome')
                            ->placeholder('Não informado'),
                        self::contacts('clientContacts'),
                    ]),
                self::party('Dados do importador', 'importador', 'importerContacts'),
                self::party('Dados do exportador', 'exportador', 'shipperContacts'),
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

    private static function party(string $title, string $relation, string $contacts): Section
    {
        return Section::make($title)
            ->columns(1)
            ->schema([
                TextEntry::make("{$relation}.nome")
                    ->label('Nome')
                    ->placeholder('Não informado'),
                self::contacts($contacts),
            ]);
    }

    /** "Nome (e-mail)" por contato da reserva, um por linha. */
    private static function contacts(string $relation): TextEntry
    {
        return TextEntry::make($relation)
            ->label('Contatos:')
            ->state(fn (Embarque $record) => $record->{$relation}->map(fn ($u) => trim("{$u->nome} ({$u->email})"))->all())
            ->listWithLineBreaks()
            ->placeholder('Não informado');
    }
}
