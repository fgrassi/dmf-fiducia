<?php

namespace App\Filament\Pages;

use App\Actions\Notices\NoticeTexts;
use App\Actions\Notices\RenderNoticeEmail;
use App\Actions\Notices\UpdateNoticeSetting;
use App\Enums\Notice;
use App\Enums\Routine;
use App\Models\NoticeSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Avisos automáticos por e-mail (cron): qual e-mail é enviado, quando, para quem, e se está ligado (como Email Notifications do
 * soprano-app). Na outra aba, as rotinas agendadas que geram dados (cobranças, ordens de pagamento, resumos das capas).
 */
class NoticeSettings extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Avisos por e-mail';

    protected static ?string $slug = 'configuracoes/avisos-por-email';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 30;

    public string $activeTab = 'avisos';

    /** Mesma credencial das Configurações do Sistema: admin ou financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential(['admin', 'financial']);
    }

    public function updatedActiveTab(): void
    {
        $this->resetTable();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()
                ->livewireProperty('activeTab')
                ->contained(false)
                ->tabs([
                    'avisos' => Tab::make('Avisos por e-mail'),
                    'rotinas' => Tab::make('Rotinas de geração'),
                ]),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search): array => $this->records($search))
            ->columns([
                TextColumn::make('label')
                    ->label('Descrição')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('days')
                    ->label('Dias'),
                TextColumn::make('time')
                    ->label('Horário')
                    ->placeholder('—'),
                ToggleColumn::make('enabled')
                    ->label('Envio')
                    ->updateStateUsing(function (array $record, mixed $state): bool {
                        app(UpdateNoticeSetting::class)($this->setting($record), ['enabled' => (bool) $state]);

                        return (bool) $state;
                    }),
                TextColumn::make('to')
                    ->label('Para')
                    ->visible(fn (): bool => $this->activeTab === 'avisos')
                    ->state(fn (array $record): string => $record['to'] ? implode(', ', $record['to']) : 'Contatos da reserva')
                    ->wrap(),
                TextColumn::make('cc')
                    ->label('Cópia')
                    ->visible(fn (): bool => $this->activeTab === 'avisos')
                    ->state(fn (array $record): string => ($record['cc'] && $record['copy_label'] !== 'Cópia' ? '(oculta) ' : '').implode(', ', $record['cc']))
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Editar')
                    ->icon(Heroicon::PencilSquare)
                    ->modalHeading(fn (array $record): string => $record['label'])
                    ->fillForm(fn (array $record): array => ['enabled' => $record['enabled'], 'time' => $record['time'], 'weekdays' => $record['weekdays'], 'recipients' => $record['to'], 'cc' => $record['cc'], 'subject' => $record['subject'] ?? $record['default_subject'], 'body' => $record['body'] ?? $record['default_body']])
                    ->modalWidth('4xl')
                    ->schema(fn (array $record): array => [
                        Toggle::make('enabled')
                            ->label('Envio ligado'),
                        TimePicker::make('time')
                            ->label('Horário')
                            ->seconds(false)
                            ->visible($record['time'] !== null)
                            ->required($record['time'] !== null),
                        CheckboxList::make('weekdays')
                            ->label('Dias da semana')
                            ->options([0 => 'Dom', 1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb'])
                            ->columns(7)
                            ->visible($record['weekdays'] !== null)
                            ->required($record['weekdays'] !== null)
                            ->minItems(1),
                        TagsInput::make('recipients')
                            ->label('Para')
                            ->visible($record['to'] !== [])
                            ->required($record['to'] !== [])
                            ->nestedRecursiveRules(['email']),
                        TagsInput::make('cc')
                            ->label($record['copy_label'])
                            ->visible(! $record['routine'])
                            ->nestedRecursiveRules(['email']),
                        ...$this->textFields($record),
                    ])
                    ->extraModalFooterActions(fn (array $record): array => $record['tags'] === [] ? [] : [
                        Action::make('previewEmail')
                            ->label('Pré-visualizar o e-mail')
                            ->icon(Heroicon::Eye)
                            ->color('gray')
                            ->modalHeading('Pré-visualização (dados de exemplo)')
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel('Fechar')
                            ->modalContent(fn (array $record, array $mountedActions): HtmlString => $this->preview($record, $mountedActions[0]->getRawData())),
                    ])
                    ->action(function (array $data, array $record): void {
                        $changes = array_filter([
                            'enabled' => (bool) $data['enabled'],
                            'time' => $record['time'] !== null ? substr($data['time'], 0, 5) : null,
                            'weekdays' => $record['weekdays'] !== null ? collect($data['weekdays'])->map(fn ($day) => (int) $day)->unique()->sort()->values()->all() : null,
                            'recipients' => $record['to'] !== [] ? $data['recipients'] : null,
                            'cc' => $record['routine'] ? null : ($data['cc'] ?? []),
                        ], fn ($value) => $value !== null);

                        if ($record['tags'] !== []) {
                            // em branco vale o texto padrão
                            $changes['subject'] = filled($data['subject'] ?? null) && $data['subject'] !== $record['default_subject'] ? $data['subject'] : null;
                            $changes['body'] = filled(trim(strip_tags((string) ($data['body'] ?? '')))) ? $data['body'] : null;
                        }

                        app(UpdateNoticeSetting::class)($this->setting($record), $changes);
                    }),
            ])
            ->paginated(false);
    }

    /** @return list<array<string, mixed>> */
    private function records(?string $search): array
    {
        $cases = $this->activeTab === 'rotinas' ? Routine::cases() : Notice::cases();
        $settings = NoticeSetting::all()->keyBy('notice');

        return collect($cases)
            ->map(fn (Notice|Routine $case): array => [
                '__key' => $case->value,
                'notice' => $case->value,
                'routine' => $case instanceof Routine,
                'label' => $case->label(),
                'days' => $case->days(),
                'time' => $case->time(),
                'weekdays' => $case->weekdays(),
                'enabled' => $case->enabled(),
                'to' => $case instanceof Notice && $case->hasFixedRecipients() ? $case->recipients() : [],
                'cc' => $case instanceof Notice ? $case->copies() : [],
                'subject' => $case instanceof Notice ? $settings->get($case->value)?->subject : null,
                'body' => $case instanceof Notice ? $settings->get($case->value)?->body : null,
                'default_subject' => $case instanceof Notice ? NoticeTexts::for($case)['subject'] : null,
                'default_body' => $case instanceof Notice ? NoticeTexts::editorBody($case) : null,
                'tags' => $case instanceof Notice ? array_map(fn (array $tag): string => $tag['label'], NoticeTexts::for($case)['tags']) : [],
                'copy_label' => $case instanceof Notice && $case->copiesAreBlind() ? 'Cópia oculta' : 'Cópia',
            ])
            ->filter(fn (array $record): bool => blank($search)
                || str_contains(mb_strtolower(implode(' ', [$record['label'], $record['days'], $record['time'], ...$record['to'], ...$record['cc']])), mb_strtolower(trim($search))))
            ->values()
            ->all();
    }

    /**
     * Assunto e corpo do e-mail com as variáveis do aviso; em branco vale o texto padrão (que o botão carrega para editar).
     *
     * @param  array<string, mixed>  $record
     * @return list<mixed>
     */
    private function textFields(array $record): array
    {
        if ($record['tags'] === []) {
            return [];
        }

        $default = NoticeTexts::for(Notice::from($record['notice']));

        return [
            TextInput::make('subject')
                ->label('Assunto')
                ->placeholder($default['subject'])
                ->helperText('Já vem com o assunto padrão; apagando tudo vale o padrão. Variáveis no formato {{variavel}}.')
                ->maxLength(255)
                ->columnSpanFull(),
            Select::make('insert_body_tag')
                ->label('Inserir variável no texto')
                ->options($record['tags'])
                ->searchable()
                ->live()
                ->dehydrated(false)
                ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                    if (blank($state)) {
                        return;
                    }

                    $body = $get('body');
                    $set('body', is_array($body)
                        ? [...$body, 'content' => [...($body['content'] ?? []), ['type' => 'paragraph', 'content' => [['type' => 'mergeTag', 'attrs' => ['id' => $state]]]]]]
                        : $body.'<p><span data-type="mergeTag" data-id="'.$state.'"></span></p>');
                    $set('insert_body_tag', null);
                })
                ->columnSpanFull(),
            RichEditor::make('body')
                ->label('Texto do e-mail')
                ->helperText('Já vem com o texto padrão; apagando tudo vale o padrão. Para inserir uma variável use o botão de variáveis da barra (digitar "{{" também abre a busca); a variável "Lista" é a tabela que a rotina calcula.')
                ->mergeTags($record['tags'])
                ->saveRelationshipsUsing(null)
                ->toolbarButtons([
                    ['bold', 'italic', 'underline', 'strike', 'link', 'textColor'],
                    ['h2', 'h3', 'alignStart', 'alignCenter', 'alignEnd'],
                    ['bulletList', 'orderedList'],
                    ['mergeTags'],
                    ['undo', 'redo'],
                ])
                ->hintAction(Action::make('loadDefaultText')
                    ->label('Carregar o texto padrão')
                    ->action(fn (Set $set) => $set('body', NoticeTexts::editorBody(Notice::from($record['notice'])))))
                ->columnSpanFull(),
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $data
     */
    private function preview(array $record, array $data): HtmlString
    {
        $notice = Notice::from($record['notice']);
        $body = $data['body'] ?? null;
        $body = is_array($body) ? RichContentRenderer::make($body)->toHtml() : $body;
        $samples = array_map(fn (array $tag): string => $tag['sample'], NoticeTexts::for($notice)['tags']);

        [$subject, $html] = app(RenderNoticeEmail::class)->render($notice, $data['subject'] ?? null, $body, $samples);

        return new HtmlString('<p class="mb-2 font-semibold">'.e($subject).'</p><iframe class="w-full rounded border border-gray-300" style="height: 28rem; background: #fff" srcdoc="'.e('<body style="font-family: sans-serif; font-size: 14px">'.$html.'</body>').'"></iframe>');
    }

    /** @param  array<string, mixed>  $record */
    private function setting(array $record): Notice|Routine
    {
        return Notice::tryFrom($record['notice']) ?? Routine::from($record['notice']);
    }
}
