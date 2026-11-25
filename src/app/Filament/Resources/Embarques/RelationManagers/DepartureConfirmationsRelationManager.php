<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use App\Actions\Booking\Confirmation\ConfirmDeparture;
use App\Actions\Booking\Confirmation\RemoveDeparture;
use App\Actions\Booking\Confirmation\SendNra;
use App\Models\Embarque;
use App\Models\Transborda;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Subaba "Embarque" do grupo Confirmação (booking/confirmacaoEmbarque do legado): as mensagens de confirmação de saída, quem
 * confirmou e quando, e o botão para confirmar (ConfirmDeparture), editar ou excluir (RemoveDeparture). Também reenvia o
 * e-mail do NRA. Fica de fora o "Enviar histórico".
 */
class DepartureConfirmationsRelationManager extends RelationManager
{
    protected static string $relationship = 'departureTrackings';

    /** Relação com o registro de quem confirmou (`departure_confirmation` ou `arrival_confirmation`). */
    protected string $confirmationRelation = 'departureConfirmations';

    protected static ?string $title = 'Confirmação de embarque';

    protected static ?string $emptyLabel = 'Embarque ainda não confirmado';

    protected string $createLabel = 'Confirmar embarque';

    protected string $dateField = 'dteEmbarque';

    protected string $dateLabel = 'Data efetiva de embarque';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('data_reg')
                ->label('Data')
                ->default(today())
                ->required(),
            DatePicker::make('saida_efetiva')
                ->label('Data efetiva de embarque')
                ->maxDate(today())
                ->required(),
            Toggle::make('finance_confirmed')
                ->label('Confirmado com o setor Financeiro?')
                ->accepted()
                ->visible(fn () => $this->needsFinanceConfirmation()),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description(fn () => $this->confirmedBy())
            ->emptyStateHeading(static::$emptyLabel)
            ->paginated(false)
            ->columns($this->columns())
            ->headerActions([
                $this->nraAction(),
                CreateAction::make()
                    ->label($this->createLabel)
                    ->using(fn (array $data, CreateAction $action) => $this->save($data, null, $action)),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data) => $this->recordData($data))
                    ->using(fn (Transborda $record, array $data, EditAction $action) => $this->save($data, $record, $action)),
                DeleteAction::make()
                    ->using(fn (Transborda $record) => $this->remove($record)),
            ]);
    }

    /** @return list<TextColumn> */
    protected function columns(): array
    {
        return [
            TextColumn::make('data_reg')
                ->label('Data')
                ->date('d/m/Y'),
            TextColumn::make($this->dateField)
                ->label($this->dateLabel)
                ->date('d/m/Y'),
            TextColumn::make('descr')
                ->label('Descrição')
                ->html()
                ->formatStateUsing(fn (?string $state) => nl2br(e($state)))
                ->wrap(),
            TextColumn::make('author.nome')
                ->label('Usuário'),
        ];
    }

    protected function needsFinanceConfirmation(): bool
    {
        return $this->booking()->impor_expor === 'e' && $this->booking()->tipo_embarque === 'm' && in_array((int) $this->booking()->cliente_id, ConfirmDeparture::FAROS_GROUP, true);
    }

    /** @return array<string, mixed> */
    protected function recordData(array $data): array
    {
        return $data + ['saida_efetiva' => $data['dteEmbarque'] ?? null];
    }

    protected function perform(array $data, ?Transborda $record): Transborda
    {
        $confirm = app(ConfirmDeparture::class);
        $tracking = $confirm($this->booking(), $data, auth()->id(), $record);
        $this->warnIfUnsent($confirm->sent);

        return $tracking;
    }

    protected function remove(Transborda $record): void
    {
        app(RemoveDeparture::class)($this->booking(), $record);
    }

    protected function booking(): Embarque
    {
        return $this->getOwnerRecord();
    }

    private function save(array $data, ?Transborda $record, Action $action): Transborda
    {
        try {
            return $this->perform($data, $record);
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->persistent()->send();
            $action->halt();
        }
    }

    private function confirmedBy(): ?string
    {
        $confirmation = $this->booking()->{$this->confirmationRelation}()->with('createdBy')->first();

        return $confirmation
            ? 'Confirmado por '.($confirmation->createdBy?->nome ?? 'não informado').' em '.date('d/m/Y', strtotime($confirmation->created_at))
            : null;
    }

    protected function warnIfUnsent(bool $sent): void
    {
        if (! $sent) {
            Notification::make()
                ->title('Atenção! Um ou mais contatos não possuíam e-mail válido ou o envio falhou.')
                ->warning()
                ->send();
        }
    }

    /** enviaEmailNra (legado): reenvia os documentos NRA ao agente da AIC. */
    private function nraAction(): Action
    {
        $nra = app(SendNra::class);

        return Action::make('enviarNra')
            ->label(fn () => $nra->sendCount($this->booking()) ? 'Reenviar e-mail NRA' : 'Enviar e-mail NRA')
            ->icon('heroicon-o-envelope')
            ->color('danger')
            ->visible(fn () => $nra->applies($this->booking()) && $nra->isAicAgent($this->booking()) && $nra->files($this->booking())->isNotEmpty())
            ->requiresConfirmation()
            ->modalHeading('Tem certeza que deseja enviar o e-mail de NRA?')
            ->modalDescription('A mensagem será enviada para: exportacao@dmflog.com.br, eddiec@shipamerican.com e aicil@shipamerican.com')
            ->action(function () use ($nra) {
                $nra($this->booking(), auth()->id());

                Notification::make()->title('E-mail NRA enviado.')->success()->send();
            });
    }
}
