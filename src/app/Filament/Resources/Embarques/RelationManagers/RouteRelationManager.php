<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use App\Actions\Booking\Route\DeleteRoute;
use App\Actions\Booking\Route\SaveRoute;
use App\Filament\Support\ReportFields;
use App\Models\EmbarqueRota;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Rota do navio da reserva (booking/rota do legado): cada alteração de rota (navio, portos, VG e datas previstas) vira
 * uma mensagem no rastreamento, enviada por e-mail aos contatos; navio de 300 m ou mais avisa a equipe.
 */
class RouteRelationManager extends RelationManager
{
    protected static string $relationship = 'routes';

    protected static ?string $title = 'Rota';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ! in_array($ownerRecord->finalizado, ['c', 'p'], true);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            ReportFields::ship('navio_id', 'Navio')
                ->required(),
            ReportFields::port('porto_origem_id', 'Porto de origem')
                ->required(),
            ReportFields::port('porto_destino_id', 'Porto de destino')
                ->required(),
            TextInput::make('vg')
                ->label('VG'),
            DatePicker::make('saida_prevista')
                ->label('Saída prevista')
                ->required(),
            DatePicker::make('chegada_prevista')
                ->label('Chegada prevista')
                ->required()
                ->afterOrEqual('saida_prevista'),
            Textarea::make('descr')
                ->label('Adicionado ao rastreamento')
                ->rows(3),
            DatePicker::make('data_reg')
                ->label('Data do rastreamento')
                ->default(today())
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['navio', 'portoOrigem', 'portoDestino', 'author']))
            ->paginated(false)
            ->emptyStateHeading('Nenhuma alteração de rota')
            ->columns([
                TextColumn::make('navio.nome')
                    ->label('Navio'),
                TextColumn::make('navio.tamanho')
                    ->label('Tamanho')
                    ->suffix('m'),
                TextColumn::make('portoOrigem.nome')
                    ->label('Porto origem'),
                TextColumn::make('portoDestino.nome')
                    ->label('Porto destino'),
                TextColumn::make('saida_prevista')
                    ->label('Saída prevista')
                    ->date('d/m/Y'),
                TextColumn::make('chegada_prevista')
                    ->label('Chegada prevista')
                    ->date('d/m/Y'),
                TextColumn::make('transit')
                    ->label('Transit time')
                    ->state(fn (EmbarqueRota $record) => $record->transitDays()),
                TextColumn::make('vg')
                    ->label('VG'),
                TextColumn::make('author.nome')
                    ->label('Alterado por'),
                TextColumn::make('created_at')
                    ->label('Data alteração')
                    ->date('d/m/Y'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Adicionar alteração de rota')
                    ->using(fn (array $data) => $this->save($data)),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(fn (EmbarqueRota $record, array $data) => $this->save($data, $record)),
                DeleteAction::make()
                    ->using(fn (EmbarqueRota $record) => app(DeleteRoute::class)($record)),
            ]);
    }

    private function save(array $data, ?EmbarqueRota $route = null): EmbarqueRota
    {
        $save = app(SaveRoute::class);
        $route = $save($this->getOwnerRecord(), $data, auth()->id(), $route);

        if ($save->unsent !== []) {
            Notification::make()
                ->title('Atenção! Um ou mais contatos não possuíam e-mail válido ou o envio falhou.')
                ->warning()
                ->send();
        }

        return $route;
    }
}
