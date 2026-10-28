<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Actions\Companies\SaveCompanyContact;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Pessoa;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** pessoa/listContatos (legado): contatos da empresa; excluir só marca o usuário como deletado (status -1). */
class ManageCompanyContacts extends ManageRelatedRecords
{
    private const YES_NO = ['s' => 'Sim', 'n' => 'Não'];

    private const BILLING = ['s' => 'Tudo', 'n' => 'Não', 'f' => 'Frete', 't' => 'Taxas'];

    private const STATUS = [0 => 'Aguardando autorização', 1 => 'OK', 2 => 'Não autorizado'];

    protected static string $resource = CompanyResource::class;

    protected static string $relationship = 'contacts';

    protected static ?string $title = 'Contatos';

    protected static ?string $navigationLabel = 'Contatos';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $modelLabel = 'contato';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nome')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->live(onBlur: true)
                    ->maxLength(255),
                Textarea::make('justificativa')
                    ->label('Justificativa')
                    ->helperText(fn () => "O domínio informado é diferente do cadastrado para essa empresa ({$this->getOwnerRecord()->domain}). Por favor insira uma justificativa.")
                    ->required()
                    ->visible(fn (Get $get, ?User $record) => $this->differsFromCompanyDomain((string) $get('email'), $record))
                    ->columnSpanFull(),
                TextInput::make('fone')
                    ->label('Telefone')
                    ->maxLength(255),
                Select::make('usuario_padrao')
                    ->label('Contato Padrão')
                    ->helperText('Caso esteja marcado como sim, este contato é automaticamente adicionado à reserva, quando a empresa for selecionada (Cliente, Shipper, Consignee ou Notify)')
                    ->options(self::YES_NO)
                    ->default('s')
                    ->required(),
                Select::make('receber_avisos')
                    ->label('Tracking')
                    ->helperText('Se marcado como sim, e o contato for adicionado à reserva, ele vai receber as mensagens de rastreamento/tracking')
                    ->options(self::YES_NO)
                    ->default('n')
                    ->required(),
                Select::make('receber_relatorio')
                    ->label('Relatórios')
                    ->helperText('Se marcado como sim, e houver agendamento do envio de relatórios para a empresa, e houver reservas em aberto, o contato receberá cópia do Mapa de Reservas')
                    ->options(self::YES_NO)
                    ->default('n')
                    ->required(),
                Select::make('receber_documentos')
                    ->label('Documentos')
                    ->helperText('Se estiver marcado como sim, e o contato estiver adicionado à reserva, ele irá receber e-mails com Cópia dos BLs')
                    ->options(self::YES_NO)
                    ->default('n')
                    ->required(),
                Select::make('receber_cobranca')
                    ->label('Cobrança')
                    ->helperText('Tudo: qualquer cobrança/recibo. Frete: só os do tipo frete. Taxas: tudo que não for frete. Não: nenhum.')
                    ->options(self::BILLING)
                    ->default('n')
                    ->required(),
                Select::make('receber_avisos_demurrage')
                    ->label('Avisos Demurrage')
                    ->helperText('Se marcado como sim, irá receber avisos do sistema sobre demurrage')
                    ->options(self::YES_NO)
                    ->default('n')
                    ->required(),
                CheckboxList::make('tipos')
                    ->label('Tipo de Contato')
                    ->helperText('Em qual tipo de reserva este contato deve aparecer')
                    ->options([3 => 'EXPORTAÇÃO', 7 => 'IMPORTAÇÃO']),
                CheckboxList::make('categorias')
                    ->label('Categorias de Frases para Tracking')
                    ->options(fn () => DB::table('frase_categoria')->where('id', '<>', 17)->orderBy('nome')->pluck('nome', 'id'))
                    ->default(fn () => DB::table('frase_categoria')->where('id', '<>', 17)->pluck('id')->all()),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('usuario.status', '<>', -1)->orWhereNull('usuario.status')))
            ->defaultSort('nome')
            ->columns([
                TextColumn::make('nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                TextColumn::make('fone')
                    ->label('Fone'),
                TextColumn::make('usuario_padrao')
                    ->label('Contato Padrão')
                    ->formatStateUsing(fn ($state) => self::YES_NO[$state] ?? 'Não'),
                TextColumn::make('receber_avisos')
                    ->label('Recebe Tracking')
                    ->formatStateUsing(fn ($state) => self::YES_NO[$state] ?? 'Não'),
                TextColumn::make('receber_relatorio')
                    ->label('Recebe Relatório')
                    ->formatStateUsing(fn ($state) => self::YES_NO[$state] ?? 'Não'),
                TextColumn::make('receber_cobranca')
                    ->label('Recebe Cobrança')
                    ->formatStateUsing(fn ($state) => self::BILLING[$state] ?? 'Não'),
                TextColumn::make('receber_documentos')
                    ->label('Recebe Documentos')
                    ->formatStateUsing(fn ($state) => self::YES_NO[$state] ?? 'Não'),
                TextColumn::make('receber_avisos_demurrage')
                    ->label('Recebe Avisos Demurrage')
                    ->formatStateUsing(fn ($state) => self::YES_NO[$state] ?? 'Não'),
                TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn ($state) => self::STATUS[(int) $state] ?? '')
                    ->badge(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Adicionar contato')
                    ->using(fn (array $data) => app(SaveCompanyContact::class)($this->getOwnerRecord(), $data, auth()->id())),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(function (array $data, User $record) {
                        $linkId = DB::table('pessoa_usuario')->where('pessoa_id', $this->getOwnerRecord()->getKey())->where('usuario_id', $record->getKey())->value('pessoa_usuario_id');

                        return [
                            ...$data,
                            'tipos' => DB::table('pessoa_usuario_tipo_usuario')->where('pessoa_usuario_id', $linkId)->pluck('tipo_usuario_id')->all(),
                            'categorias' => DB::table('usuario_frase_categoria')->where('ref_usuario', $record->getKey())->pluck('ref_categoria_frase')->all(),
                        ];
                    })
                    ->using(fn (User $record, array $data) => app(SaveCompanyContact::class)($this->getOwnerRecord(), $data, auth()->id(), $record)),
                DeleteAction::make()
                    ->using(fn (User $record) => $record->forceFill(['status' => -1])->save()),
            ]);
    }

    private function differsFromCompanyDomain(string $email, ?User $record): bool
    {
        /** @var Pessoa $company */
        $company = $this->getOwnerRecord();

        return filled($company->domain)
            && filled($email)
            && (! $record || $email !== $record->email)
            && str($email)->after('@')->toString() !== $company->domain;
    }
}
