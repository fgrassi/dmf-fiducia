<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use App\Actions\Booking\Ams\ConfirmAms;
use App\Actions\Booking\Ams\SendAms;
use App\Enums\AmsDestination;
use App\Models\AmsRegistration;
use App\Models\Embarque;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Abas "Africa do Sul" e "Emirados Árabes" da reserva (booking/amsAfrica e amsUae do legado): manda o registro do embarque
 * ao agente do destino, por e-mail, e guarda o arquivo da confirmação dele. Fica de fora o aviso e o lembrete
 * automáticos (crons).
 */
abstract class AmsRelationManager extends RelationManager
{
    private const STATUSES = [1 => 'Pendente de envio ao agente', 2 => 'Enviado ao agente, pendente de confirmação', 3 => 'Confirmado'];

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (int) $ownerRecord->portoDestino?->ref_pais === static::destinationOf()->country()
            && ! in_array($ownerRecord->finalizado, ['c', 'p'], true);
    }

    abstract protected static function destinationOf(): AmsDestination;

    public function table(Table $table): Table
    {
        $destination = static::destinationOf();

        return $table
            ->description(fn () => $this->agentDescription())
            ->paginated(false)
            ->emptyStateHeading('As informações não foram enviadas')
            ->columns([
                TextColumn::make('data_envio')
                    ->label('Data de envio')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('arquivo')
                    ->label('Arquivo')
                    ->formatStateUsing(fn () => 'Baixar')
                    ->url(fn (AmsRegistration $record) => $record->arquivo ? Storage::disk('tracking_uploads')->url($destination->directory().'/'.rawurlencode($record->arquivo)) : null, true),
                TextColumn::make('data_confirmacao')
                    ->label('Data de recebimento')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn () => self::STATUSES[(int) $this->getOwnerRecord()->{$destination->statusColumn()}] ?? null),
            ])
            ->headerActions([
                Action::make('enviar')
                    ->label('Enviar informações')
                    ->icon('heroicon-o-paper-airplane')
                    ->requiresConfirmation()
                    ->modalHeading('Tem certeza que deseja enviar o e-mail?')
                    ->visible(fn () => ! $this->getOwnerRecord()->{$destination->relation()}()->whereNotNull('data_confirmacao')->exists())
                    ->action(fn () => $this->send()),
            ])
            ->recordActions([
                Action::make('confirmar')
                    ->label('Anexar confirmação')
                    ->icon('heroicon-o-paper-clip')
                    ->visible(fn (AmsRegistration $record) => blank($record->arquivo))
                    ->schema([
                        FileUpload::make('arquivo')
                            ->label('Arquivo da confirmação')
                            ->required()
                            ->storeFiles(false)
                            ->acceptedFileTypes(ConfirmAms::MIME_TYPES)
                            ->maxSize(10240),
                    ])
                    ->action(function (AmsRegistration $record, array $data) use ($destination) {
                        app(ConfirmAms::class)($destination, $this->getOwnerRecord(), $record, $data['arquivo']);

                        Notification::make()
                            ->title('Confirmação registrada')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    private function send(): void
    {
        /** @var Embarque $booking */
        $booking = $this->getOwnerRecord();

        try {
            app(SendAms::class)(static::destinationOf(), $booking, auth()->id());
        } catch (Throwable $e) {
            report($e);
            Notification::make()
                ->title('Não foi possível enviar o e-mail. Tente novamente.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Informações enviadas ao agente')
            ->success()
            ->send();
    }

    private function agentDescription(): HtmlString|string
    {
        /** @var Embarque $booking */
        $booking = $this->getOwnerRecord();

        if (! $booking->agente) {
            return 'Nenhum agente associado a este embarque.';
        }

        $contacts = SendAms::agentContacts($booking);

        return $contacts === []
            ? "Agente {$booking->agente->nome} sem contatos cadastrados."
            : new HtmlString('Contatos do agente '.e($booking->agente->nome).': '.collect($contacts)->map(fn (array $c) => e("{$c['name']} ({$c['email']})"))->implode(', '));
    }
}
