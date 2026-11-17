<?php

namespace App\Filament\Support;

use App\Actions\Booking\Bls\PrintBl;
use App\Models\Bl;
use App\Models\Embarque;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Toggle;
use Filament\Support\Icons\Heroicon;

/** Imprimir Original, Imprimir Não Negociável, Visualizar BL PDF e Draft Formato BL (botões do BL, legado). */
class BlPrintActions
{
    /** @param Closure(): Embarque $booking */
    public static function group(Closure $booking, ?Closure $bl = null): ActionGroup
    {
        return ActionGroup::make([
            self::make('imprimirOriginal', fn (Bl $record): string => "Imprimir Original ({$record->cntOriginal}/{$record->numImpressao})", PrintBl::ORIGINAL, $bl, preprinted: true),
            self::make('imprimirNaoNegociavel', fn (Bl $record): string => "Imprimir Não Negociável ({$record->cntImpressao})", PrintBl::NON_NEGOTIABLE, $bl, preprinted: true),
            self::make('visualizarPdf', fn (): string => 'Visualizar BL PDF', null, $bl),
            self::make('draftPdf', fn (): string => 'Draft Formato BL', null, $bl, draft: true)
                ->visible(fn (): bool => $booking()->impor_expor === 'e'),
        ])
            ->label('Imprimir')
            ->icon(Heroicon::OutlinedPrinter)
            ->button();
    }

    /** As impressões perguntam do triângulo do campo marcas (Kuwait). Na página do BL, `$bl` devolve o BL aberto. */
    private static function make(string $name, Closure $label, ?string $kind, ?Closure $bl, bool $preprinted = false, bool $draft = false): Action
    {
        return Action::make($name)
            ->label($bl ? fn () => $label($bl()) : $label)
            ->modalHeading($bl ? fn () => $label($bl()) : $label)
            ->modalSubmitActionLabel('Gerar PDF')
            ->schema([
                Toggle::make('marks')
                    ->label('Exibir triângulo no campo marcas, quando necessário (Kuwait)'),
            ])
            ->action(fn (array $data, ?Bl $record = null) => app(PrintBl::class)($bl ? $bl() : $record, $kind, $preprinted, $draft, (bool) ($data['marks'] ?? false)));
    }
}
