<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use App\Actions\Booking\Imo\SaveImoConfirmation;
use App\Models\ControleImoCi;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Aba IMO da reserva de importação, exceto a aérea (booking/imo do legado): status do IMO e o controle do envio e da confirmação de recebimento pelo armador. */
class ImoRelationManager extends RelationManager
{
    protected static string $relationship = 'imos';

    protected static ?string $title = 'IMO';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->impor_expor === 'i' && $ownerRecord->tipo_embarque !== 'a' && ! in_array($ownerRecord->finalizado, ['c', 'p'], true);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('envio_imo_armador')
                ->label('Envio ao armador')
                ->required(),
            DatePicker::make('confirmacao_imo_armador')
                ->label('Confirmação de recebimento do armador'),
            FileUpload::make('arquivo')
                ->label('Arquivo da confirmação de recebimento')
                ->storeFiles(false)
                ->acceptedFileTypes(SaveImoConfirmation::MIME_TYPES)
                ->maxSize(10240)
                ->visibleOn('edit'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->emptyStateHeading('Não há registro do envio da documentação para o armador')
            ->columns([
                TextColumn::make('envio_imo_armador')
                    ->label('Envio ao armador')
                    ->date('d/m/Y'),
                TextColumn::make('confirmacao_imo_armador')
                    ->label('Confirmação de recebimento')
                    ->date('d/m/Y'),
                TextColumn::make('ref_file_confirmacao_imo_armador')
                    ->label('Arquivo')
                    ->url(fn (ControleImoCi $record) => $record->ref_file_confirmacao_imo_armador ? Storage::disk('tracking_uploads')->url('imo/'.rawurlencode($record->ref_file_confirmacao_imo_armador)) : null, true),
            ])
            ->headerActions([
                $this->statusAction(),
                CreateAction::make()
                    ->label('Registrar envio ao armador')
                    ->visible(fn () => ! $this->getOwnerRecord()->imos()->exists())
                    ->createAnother(false),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(function (ControleImoCi $record, array $data): ControleImoCi {
                        $record->update(['envio_imo_armador' => $data['envio_imo_armador']]);
                        app(SaveImoConfirmation::class)($record, $data['confirmacao_imo_armador'] ?? null, $data['arquivo'] ?? null);

                        return $record;
                    }),
                Action::make('removerArquivo')
                    ->label('Remover arquivo')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ControleImoCi $record) => filled($record->ref_file_confirmacao_imo_armador))
                    ->action(fn (ControleImoCi $record) => $record->update(['ref_file_confirmacao_imo_armador' => null, 'confirmacao_imo_armador' => null])),
                DeleteAction::make(),
            ]);
    }

    private function statusAction(): Action
    {
        return Action::make('status')
            ->label(fn () => 'Status IMO: '.($this->getOwnerRecord()->controle_imo === 's' ? 'OK' : 'não informado'))
            ->color('gray')
            ->fillForm(fn () => ['controle_imo' => $this->getOwnerRecord()->controle_imo])
            ->schema([
                Select::make('controle_imo')
                    ->label('Status do IMO')
                    ->options(['s' => 'OK']),
            ])
            ->action(fn (array $data) => $this->getOwnerRecord()->forceFill(['controle_imo' => $data['controle_imo'] ?? ''])->save());
    }
}
