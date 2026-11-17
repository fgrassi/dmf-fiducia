<?php

namespace App\Filament\Resources\Demurrages;

use App\Actions\ContainerTimes\DeleteContainerTime;
use App\Actions\ContainerTimes\SaveContainerTime;
use App\Enums\ContainerTime;
use App\Filament\Resources\Demurrages\Pages\ListDemurrages;
use App\Filament\Support\ContainerTimeForm;
use App\Filament\Support\ContainerTimeTable;
use App\Models\FinDemurrage;
use BackedEnum;
use DomainException;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Controle de Demurrage (módulo demurrage do legado): containers da importação, na chegada, com as datas de retirada e devolução. */
class DemurrageResource extends Resource
{
    protected static ?string $model = FinDemurrage::class;

    protected static ?string $modelLabel = 'Controle de Demurrage';

    protected static ?string $pluralModelLabel = 'Controle de Demurrage';

    protected static ?string $navigationLabel = 'Controle de Demurrage';

    protected static ?string $slug = 'booking/demurrage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBoxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Reserva';

    protected static ?string $navigationParentItem = 'Booking';

    protected static ?int $navigationSort = 60;

    public static function getEloquentQuery(): Builder
    {
        return ContainerTimeTable::query(ContainerTime::Demurrage);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('fin_demurrage.id', 'desc')
            ->paginated([50, 100, 200])
            ->columns(ContainerTimeTable::columns(ContainerTime::Demurrage))
            ->recordActions([
                EditAction::make()
                    ->modalHeading(fn (FinDemurrage $record) => "Demurrage: {$record->container}")
                    ->fillForm(fn (FinDemurrage $record): array => $record->only(['container', 'ref_tipo_container', 'free_time', 'data_retirada', 'data_devolucao']))
                    ->schema(fn (FinDemurrage $record) => ContainerTimeForm::components(ContainerTime::Demurrage, fn () => $record->ref_embarque))
                    ->using(function (FinDemurrage $record, array $data, EditAction $action) {
                        try {
                            $fresh = FinDemurrage::findOrFail($record->getKey());

                            app(SaveContainerTime::class)(ContainerTime::Demurrage, $fresh->embarque, $data, $fresh, auth()->id());
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                            $action->halt();
                        }

                        return $record;
                    }),
                DeleteAction::make()
                    ->using(fn (FinDemurrage $record) => app(DeleteContainerTime::class)(FinDemurrage::findOrFail($record->getKey()))),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDemurrages::route('/'),
        ];
    }
}
