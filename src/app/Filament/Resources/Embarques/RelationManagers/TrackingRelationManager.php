<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use App\Actions\Booking\Tracking\DuplicateTracking;
use App\Actions\Booking\Tracking\FillTrackingPhrase;
use App\Actions\Booking\Tracking\SaveTracking;
use App\Actions\Booking\Tracking\TrackingRecipients;
use App\Filament\Support\ReportFields;
use App\Models\Embarque;
use App\Models\FraseTransborda;
use App\Models\Transborda;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * Rastreamento da reserva (booking/listRastreamento do legado): as mensagens de tracking, com a frase pronta, o ETA e o
 * navio de destino; cada mensagem salva ou duplicada sai por e-mail aos contatos da reserva. Fica de fora o "Enviar histórico", o
 * cadastro de contatos da reserva e o Portal Integrador.
 */
class TrackingRelationManager extends RelationManager
{
    protected static string $relationship = 'trackings';

    protected static ?string $title = 'Rastreamento';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        /** @var Embarque $booking */
        $booking = $this->getOwnerRecord();

        return $schema
            ->components([
                Select::make('ref_frase_transborda')
                    ->label('Frase')
                    ->options(fn () => $this->phraseOptions())
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn ($state, $set) => $set('descr', $state ? app(FillTrackingPhrase::class)($booking, (string) FraseTransborda::find($state)?->frase) : '')),
                Textarea::make('descr')
                    ->label('Descrição')
                    ->required()
                    ->rows(8),
                DatePicker::make('data_reg')
                    ->label('Data')
                    ->default(today())
                    ->required(),
                DatePicker::make('eta')
                    ->label('ETA')
                    ->default($booking->chegada_prevista),
                ReportFields::ship('navio_id_destino', 'Navio de destino')
                    ->default($booking->navio_id_destino),
                Text::make(fn () => new HtmlString('A mensagem será enviada para: '.$this->recipientsList()))
                    ->visibleOn('create'),
                Select::make('other_bookings')
                    ->label('Aplicar também a outros bookings')
                    ->multiple()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Embarque::query()->where('booking', 'like', "%{$search}%")->whereKeyNot($booking->getKey())->orderByDesc('data_topo')->limit(30)->pluck('booking', 'embarque_id'))
                    ->getOptionLabelsUsing(fn (array $values) => Embarque::query()->whereKey($values)->pluck('booking', 'embarque_id')->all())
                    ->visibleOn('create'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['author', 'phrase']))
            ->paginated([25, 50, 100])
            ->emptyStateHeading('Nenhuma mensagem de rastreamento')
            ->columns([
                TextColumn::make('data_reg')
                    ->label('Data')
                    ->date('d/m/Y'),
                TextColumn::make('descr')
                    ->label('Descrição')
                    ->html()
                    ->formatStateUsing(fn (?string $state) => nl2br(e($state)))
                    ->wrap(),
                TextColumn::make('author.nome')
                    ->label('Usuário'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Adicionar mensagem')
                    ->using(fn (array $data) => $this->save($data)),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Transborda $record) => ! $record->isSystemGenerated())
                    ->mutateRecordDataUsing(fn (array $data) => $data + ['eta' => $this->getOwnerRecord()->chegada_prevista, 'navio_id_destino' => $this->getOwnerRecord()->navio_id_destino])
                    ->using(fn (Transborda $record, array $data) => $this->save($data, $record)),
                Action::make('duplicar')
                    ->label('Duplicar')
                    ->icon('heroicon-o-document-duplicate')
                    ->requiresConfirmation()
                    ->modalHeading('Duplicar esta mensagem?')
                    ->modalDescription('A cópia leva a data de hoje e é enviada por e-mail aos contatos.')
                    ->visible(fn (Transborda $record) => ! $record->isSystemGenerated())
                    ->action(fn (Transborda $record) => $this->warnIfUnsent(app(DuplicateTracking::class)($record, auth()->id()) ? [] : [$record->embarque_id])),
                DeleteAction::make()
                    ->visible(fn (Transborda $record) => ! $record->isSystemGenerated()),
            ]);
    }

    private function recipientsList(): string
    {
        $contacts = app(TrackingRecipients::class)($this->getOwnerRecord());

        return $contacts === []
            ? '<em>nenhum contato</em>'
            : '<ul>'.collect($contacts)->map(fn (array $c) => '<li>'.e("{$c['nome']} ({$c['email']}) [{$c['tipo']}]").'</li>')->implode('').'</ul>';
    }

    private function save(array $data, ?Transborda $record = null): Transborda
    {
        $save = app(SaveTracking::class);
        $tracking = $save($this->getOwnerRecord(), $data, auth()->id(), $record);
        $this->warnIfUnsent($save->unsent);

        return $tracking;
    }

    /** @param list<int|string> $unsent */
    private function warnIfUnsent(array $unsent): void
    {
        if ($unsent !== []) {
            Notification::make()
                ->title('Atenção! Um ou mais contatos não possuíam e-mail válido ou o envio falhou.')
                ->warning()
                ->send();
        }
    }

    /**
     * Frases por categoria (sem as categorias do sistema) e, no fim, as sem categoria.
     *
     * @return array<string, array<int, string>>
     */
    private function phraseOptions(): array
    {
        $phrases = FraseTransborda::query()->orderBy('valor')->get()->groupBy('categoria_id');
        $categories = DB::table('frase_categoria')->whereNotIn('id', Transborda::SYSTEM_CATEGORIES)->orderBy('nome')->pluck('nome', 'id');

        return $categories
            ->mapWithKeys(fn (string $name, int $id) => [$name => ($phrases[$id] ?? collect())->pluck('valor', 'id')->all()])
            ->filter()
            ->when($phrases->has(''), fn ($groups) => $groups->put('(sem categoria)', $phrases['']->pluck('valor', 'id')->all()))
            ->all();
    }
}
