<?php

namespace App\Filament\Pages;

use App\Actions\Notices\UpdateNoticeSetting;
use App\Enums\Notice;
use App\Enums\Routine;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
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
                    ->fillForm(fn (array $record): array => ['enabled' => $record['enabled'], 'time' => $record['time'], 'recipients' => $record['to'], 'cc' => $record['cc']])
                    ->schema(fn (array $record): array => [
                        Toggle::make('enabled')
                            ->label('Envio ligado'),
                        TimePicker::make('time')
                            ->label('Horário')
                            ->seconds(false)
                            ->visible($record['time'] !== null)
                            ->required($record['time'] !== null),
                        TagsInput::make('recipients')
                            ->label('Para')
                            ->visible($record['to'] !== [])
                            ->required($record['to'] !== [])
                            ->nestedRecursiveRules(['email']),
                        TagsInput::make('cc')
                            ->label($record['copy_label'])
                            ->visible(! $record['routine'])
                            ->nestedRecursiveRules(['email']),
                    ])
                    ->action(function (array $data, array $record): void {
                        app(UpdateNoticeSetting::class)($this->setting($record), array_filter([
                            'enabled' => (bool) $data['enabled'],
                            'time' => $record['time'] !== null ? substr($data['time'], 0, 5) : null,
                            'recipients' => $record['to'] !== [] ? $data['recipients'] : null,
                            'cc' => $record['routine'] ? null : ($data['cc'] ?? []),
                        ], fn ($value) => $value !== null));
                    }),
            ])
            ->paginated(false);
    }

    /** @return list<array<string, mixed>> */
    private function records(?string $search): array
    {
        $cases = $this->activeTab === 'rotinas' ? Routine::cases() : Notice::cases();

        return collect($cases)
            ->map(fn (Notice|Routine $case): array => [
                '__key' => $case->value,
                'notice' => $case->value,
                'routine' => $case instanceof Routine,
                'label' => $case->label(),
                'days' => $case->days(),
                'time' => $case->time(),
                'enabled' => $case->enabled(),
                'to' => $case instanceof Notice && $case->hasFixedRecipients() ? $case->recipients() : [],
                'cc' => $case instanceof Notice ? $case->copies() : [],
                'copy_label' => $case instanceof Notice && $case->copiesAreBlind() ? 'Cópia oculta' : 'Cópia',
            ])
            ->filter(fn (array $record): bool => blank($search)
                || str_contains(mb_strtolower(implode(' ', [$record['label'], $record['days'], $record['time'], ...$record['to'], ...$record['cc']])), mb_strtolower(trim($search))))
            ->values()
            ->all();
    }

    /** @param  array<string, mixed>  $record */
    private function setting(array $record): Notice|Routine
    {
        return Notice::tryFrom($record['notice']) ?? Routine::from($record['notice']);
    }
}
