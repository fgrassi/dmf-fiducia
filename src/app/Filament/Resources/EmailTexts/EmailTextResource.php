<?php

namespace App\Filament\Resources\EmailTexts;

use App\Filament\Resources\EmailTexts\Pages\ManageEmailTexts;
use App\Models\FinEmailTexto;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * finEmailTexto (legado): textos do e-mail da Instrução de Cobrança. O corpo é editado como HTML: o legado usa o
 * TinyMCE, e o editor rico daqui apagaria o <font> e os estilos dos textos.
 */
class EmailTextResource extends Resource
{
    private const YES_NO = [1 => 'Sim', 0 => 'Não'];

    protected static ?string $model = FinEmailTexto::class;

    protected static ?string $modelLabel = 'Texto de E-mail (IC)';

    protected static ?string $pluralModelLabel = 'Textos de E-mail (IC)';

    protected static ?string $navigationLabel = 'Textos E-mail IC';

    protected static ?string $slug = 'textos-email-ic';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 41;

    /** security.yml do módulo (legado): credencial financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('mnemonico')
                    ->label('Mnemônico')
                    ->helperText('Código único (ex.: IC_EMAIL_IMPO). Não muda depois de criado.')
                    ->required()
                    ->maxLength(80)
                    ->unique(ignoreRecord: true)
                    ->disabledOn('edit'),
                TextInput::make('titulo')
                    ->label('Título')
                    ->maxLength(255),
                Textarea::make('caso_uso')
                    ->label('Caso de uso')
                    ->helperText('Quando este texto é usado no e-mail da IC.')
                    ->maxLength(500)
                    ->rows(2),
                Select::make('ativo')
                    ->label('Ativo')
                    ->options(self::YES_NO)
                    ->default(1)
                    ->selectablePlaceholder(false),
                Textarea::make('corpo')
                    ->label('Corpo (HTML)')
                    ->helperText('Marcadores: '.collect(FinEmailTexto::PLACEHOLDERS)->map(fn (string $name) => '{{'.$name.'}}')->implode(' ').'. O despachante (importação) é o padrão do porto de descarga; o endereço são os dados de contato dele.')
                    ->rows(18)
                    ->extraInputAttributes(['class' => 'font-mono text-sm']),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('mnemonico')
            ->emptyStateHeading('Nenhum registro encontrado')
            ->columns([
                TextColumn::make('mnemonico')
                    ->label('Mnemônico')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('titulo')
                    ->label('Título')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('caso_uso')
                    ->label('Caso de uso')
                    ->wrap(),
                TextColumn::make('ativo')
                    ->label('Ativo')
                    ->formatStateUsing(fn ($state) => self::YES_NO[(int) $state]),
                TextColumn::make('updated_at')
                    ->label('Atualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('ativo')
                    ->label('Ativo')
                    ->options(self::YES_NO),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateDataUsing(fn (array $data) => $data + ['updated_by' => auth()->id()]),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageEmailTexts::route('/'),
        ];
    }
}
