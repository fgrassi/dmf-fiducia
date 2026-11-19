<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use App\Actions\Booking\ShippingInstruction\AddInstructionFile;
use App\Actions\Booking\ShippingInstruction\SendShippingInstruction;
use App\Actions\Booking\ShippingInstruction\ShippingInstructionMail;
use App\Models\Embarque;
use App\Models\EmbEmbarqueFile;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Throwable;

/** Aba Instr. Embarque da importação (booking/instrucaoEmbarque do legado): instrução de embarque enviada aos contatos do agente na origem, com os arquivos anexados. */
class ShippingInstructionRelationManager extends RelationManager
{
    protected static string $relationship = 'instructionFiles';

    protected static ?string $title = 'Instr. Embarque';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->impor_expor === 'i' && ! in_array($ownerRecord->finalizado, ['c', 'p'], true);
    }

    public function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->description(fn () => $this->summary())
            ->emptyStateHeading('Nenhum arquivo anexado à instrução de embarque')
            ->columns([
                TextColumn::make('file.name')
                    ->label('Arquivo')
                    ->url(fn (EmbEmbarqueFile $record) => $record->file ? Storage::disk('tracking_uploads')->url('docs_emb/'.rawurlencode($record->file->name)) : null, true),
                TextColumn::make('ref_file_type')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($state) => DB::table('emb_embarque_files_types')->where('id', $state)->value('name')),
            ])
            ->headerActions([
                Action::make('adicionar')
                    ->label('Adicionar arquivo')
                    ->icon('heroicon-o-paper-clip')
                    ->color('gray')
                    ->schema([
                        Select::make('type')
                            ->label('Tipo')
                            ->options(fn () => DB::table('emb_embarque_files_types')->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                        FileUpload::make('file')
                            ->label('Arquivo')
                            ->required()
                            ->storeFiles(false)
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'])
                            ->maxSize(10240),
                    ])
                    ->action(function (array $data) {
                        app(AddInstructionFile::class)($this->booking(), (int) $data['type'], $data['file'], auth()->id());

                        Notification::make()->title('Arquivo adicionado')->success()->send();
                    }),
                Action::make('previa')
                    ->label('Prévia do e-mail')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('Prévia do e-mail ao agente')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->modalContent(fn () => $this->preview()),
                Action::make('enviar')
                    ->label('Enviar Instrução de Embarque')
                    ->icon('heroicon-o-paper-airplane')
                    ->requiresConfirmation()
                    ->modalDescription('Tem certeza que deseja enviar a Instrução de Embarque e os documentos relacionados a todos os contatos do Agente?')
                    ->schema([
                        Textarea::make('notes')
                            ->label('Instruções para os agentes')
                            ->rows(4)
                            ->maxLength(2000),
                    ])
                    ->action(fn (array $data) => $this->send((string) ($data['notes'] ?? ''))),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->modalHeading('Confirmar exclusão?'),
            ]);
    }

    private function booking(): Embarque
    {
        /** @var Embarque */
        return $this->getOwnerRecord();
    }

    private function send(string $notes): void
    {
        try {
            app(SendShippingInstruction::class)($this->booking(), auth()->user(), $notes);
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Falha no envio')->body('Não foi possível enviar a instrução de embarque.')->danger()->send();

            return;
        }

        Notification::make()->title('Documentos enviados com Sucesso!')->success()->send();
    }

    private function preview(): HtmlString
    {
        $user = auth()->user();
        $message = app(ShippingInstructionMail::class)($this->booking(), '[contact name]', '', $user->nome, (string) $user->email);

        return new HtmlString('<p class="mb-2 font-semibold">'.e($message['subject']).'</p><iframe class="w-full rounded border border-gray-300" style="height: 28rem; background: #fff" srcdoc="'.e($message['html']).'"></iframe>');
    }

    /** Contatos do agente na origem e histórico de envios (rastreamento evento "t"). */
    private function summary(): HtmlString
    {
        $booking = $this->booking();
        $contacts = SendShippingInstruction::contacts($booking);
        $contactLine = $contacts === []
            ? 'Agente '.e((string) $booking->agente?->nome).' sem nenhum contato selecionado para o porto de origem'
            : 'Contatos do agente: '.collect($contacts)->map(fn ($c) => e("{$c->name} ({$c->email})"))->implode(', ');
        $history = DB::table('rastreamento')->where('ref_embarque', $booking->getKey())->where('evento', SendShippingInstruction::EVENT)->orderBy('id')->pluck('historico');

        return new HtmlString($contactLine.($history->isEmpty() ? '' : '<br>Histórico de envios: '.$history->map(fn ($h) => e((string) $h))->implode(' | ')));
    }
}
