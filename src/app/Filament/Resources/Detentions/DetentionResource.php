<?php

namespace App\Filament\Resources\Detentions;

use App\Actions\ContainerTimes\DeleteContainerTime;
use App\Actions\ContainerTimes\SaveContainerTime;
use App\Enums\ContainerTime;
use App\Filament\Resources\Detentions\Pages\ListDetentions;
use App\Filament\Support\ContainerTimeForm;
use App\Filament\Support\ContainerTimeTable;
use App\Models\FinDetention;
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

/** Controle de Detention (módulo detention do legado): containers da exportação, na saída, com as datas de retirada e devolução. */
class DetentionResource extends Resource
{
    protected static ?string $model = FinDetention::class;

    protected static ?string $modelLabel = 'Controle de Detention';

    protected static ?string $pluralModelLabel = 'Controle de Detention';

    protected static ?string $navigationLabel = 'Controle de Detention';

    protected static ?string $slug = 'booking/detention';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBoxXMark;

    protected static string|UnitEnum|null $navigationGroup = 'Reserva';

    protected static ?string $navigationParentItem = 'Booking';

    protected static ?int $navigationSort = 61;

    public static function getEloquentQuery(): Builder
    {
        return ContainerTimeTable::query(ContainerTime::Detention);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('fin_detention.id', 'desc')
            ->paginated([50, 100, 200])
            ->columns(ContainerTimeTable::columns(ContainerTime::Detention))
            ->recordActions([
                EditAction::make()
                    ->modalHeading(fn (FinDetention $record) => "Detention: {$record->container}")
                    ->fillForm(fn (FinDetention $record): array => $record->only(['container', 'ref_tipo_container', 'free_time', 'data_retirada', 'data_devolucao']))
                    ->schema(fn (FinDetention $record) => ContainerTimeForm::components(ContainerTime::Detention, fn () => $record->ref_embarque))
                    ->using(function (FinDetention $record, array $data, EditAction $action) {
                        try {
                            $fresh = FinDetention::findOrFail($record->getKey());

                            app(SaveContainerTime::class)(ContainerTime::Detention, $fresh->embarque, $data, $fresh, auth()->id());
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                            $action->halt();
                        }

                        return $record;
                    }),
                DeleteAction::make()
                    ->using(fn (FinDetention $record) => app(DeleteContainerTime::class)(FinDetention::findOrFail($record->getKey()))),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDetentions::route('/'),
        ];
    }
}
