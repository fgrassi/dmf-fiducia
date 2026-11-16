<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use App\Actions\ContainerTimes\DeleteContainerTime;
use App\Actions\ContainerTimes\SaveContainerTime;
use App\Enums\ContainerTime;
use App\Filament\Support\ContainerTimeForm;
use App\Filament\Support\ContainerTimeTable;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Abas Demurrage (importação) e Detention (exportação) da reserva: cadastro dos containers e das datas (demurrage / detention do booking, legado). */
abstract class ContainerTimeRelationManager extends RelationManager
{
    abstract protected function type(): ContainerTime;

    /** A aba só existe para a operação do tipo: demurrage na importação, detention na exportação. */
    protected static string $operation;

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->impor_expor === static::$operation;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(ContainerTimeForm::components($this->type(), fn () => $this->getOwnerRecord()->getKey()));
    }

    public function table(Table $table): Table
    {
        $type = $this->type();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('tipoContainer'))
            ->paginated(false)
            ->emptyStateHeading('Nenhum container cadastrado')
            ->columns([
                TextColumn::make('container')
                    ->label('Container'),
                TextColumn::make('tipoContainer.nome')
                    ->label('Tipo Container'),
                TextColumn::make('data_retirada')
                    ->label($type === ContainerTime::Demurrage ? 'Data descarga' : 'Data retirada')
                    ->date('d/m/Y'),
                TextColumn::make('data_devolucao')
                    ->label('Data devolução')
                    ->date('d/m/Y'),
                TextColumn::make('free_time')
                    ->label('Free Time')
                    ->state(fn (Model $record) => $record->freeTimeDays() ?: null)
                    ->placeholder('-'),
                TextColumn::make('operation_days')
                    ->label('Tempo operação')
                    ->state(fn (Model $record) => $record->operationDays())
                    ->placeholder('-'),
                TextColumn::make('overdue_days')
                    ->label($type->label())
                    ->state(fn (Model $record) => $record->overdueDays())
                    ->placeholder('-')
                    ->color(fn (Model $record) => $record->isCharged() ? 'danger' : null),
                ...($type === ContainerTime::Demurrage ? [ContainerTimeTable::fileColumn()] : []),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Adicionar container')
                    ->using(fn (array $data, Action $action) => $this->save(null, $data, $action)),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(fn (Model $record, array $data, Action $action) => $this->save($record, $data, $action)),
                DeleteAction::make()
                    ->using(fn (Model $record) => app(DeleteContainerTime::class)($record)),
            ]);
    }

    private function save(?Model $record, array $data, Action $action): Model
    {
        try {
            return app(SaveContainerTime::class)($this->type(), $this->getOwnerRecord(), $data, $record, auth()->id());
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $action->halt();
        }
    }
}
