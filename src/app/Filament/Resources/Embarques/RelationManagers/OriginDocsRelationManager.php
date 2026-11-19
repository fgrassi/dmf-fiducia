<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use App\Actions\Booking\Schedule\SaveOriginDocs;
use App\Models\LiberacaoEmbarqueOrigem;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Aba Envio Doc. Origem da reserva (booking/envioDocumentosOrigem do legado): a data em que os documentos foram enviados ao exportador na origem. */
class OriginDocsRelationManager extends RelationManager
{
    protected static string $relationship = 'originReleases';

    protected static ?string $title = 'Envio Doc. Origem';

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
            DatePicker::make('data_liberacao')
                ->label('Data de envio')
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('author'))
            ->paginated(false)
            ->emptyStateHeading('Envio dos documentos à origem ainda não informado')
            ->columns([
                TextColumn::make('data_liberacao')
                    ->label('Data de envio')
                    ->date('d/m/Y'),
                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn ($state) => (int) $state === LiberacaoEmbarqueOrigem::SENT ? 'Enviado' : 'Pendente')
                    ->color(fn ($state) => (int) $state === LiberacaoEmbarqueOrigem::SENT ? 'success' : 'warning'),
                TextColumn::make('author.nome')
                    ->label('Atualizado por'),
                TextColumn::make('updated_at')
                    ->label('Atualizado em')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Informar envio')
                    ->visible(fn () => ! $this->getOwnerRecord()->originReleases()->exists())
                    ->createAnother(false)
                    ->using(fn (array $data) => app(SaveOriginDocs::class)($this->getOwnerRecord(), $data['data_liberacao'], auth()->id())),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(fn (LiberacaoEmbarqueOrigem $record, array $data) => app(SaveOriginDocs::class)($this->getOwnerRecord(), $data['data_liberacao'], auth()->id(), $record)),
            ]);
    }
}
