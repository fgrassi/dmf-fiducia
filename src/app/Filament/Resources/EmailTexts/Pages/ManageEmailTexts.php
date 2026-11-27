<?php

namespace App\Filament\Resources\EmailTexts\Pages;

use App\Actions\Cobranca\Documents\PreviewInstructionEmail;
use App\Filament\Resources\EmailTexts\EmailTextResource;
use App\Models\FinCharges;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;

class ManageEmailTexts extends ManageRecords
{
    protected static string $resource = EmailTextResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // finEmailTexto/preview: o e-mail como sairia para uma cobrança, sem enviar nada
            Action::make('preview')
                ->label('Preview e-mail IC')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->modalWidth('7xl')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Fechar')
                ->schema([
                    Select::make('charge')
                        ->label('Cobrança (busque pelo BL, HBL ou número)')
                        ->searchable()
                        ->live()
                        ->getSearchResultsUsing(fn (string $search) => self::options($search))
                        ->getOptionLabelUsing(fn ($value) => self::options((string) $value)[$value] ?? $value),
                    Checkbox::make('recalculated')
                        ->label('Como depois do recálculo (libera o cliente com taxa negociada)')
                        ->live(),
                    Html::make(fn (Get $get) => self::preview($get('charge'), (bool) $get('recalculated'))),
                ]),
            CreateAction::make()
                ->label('Novo texto')
                ->mutateDataUsing(fn (array $data) => $data + ['updated_by' => auth()->id()]),
        ];
    }

    /** @return array<int, string> */
    private static function options(string $search): array
    {
        return FinCharges::query()
            ->with(['embarque', 'bl'])
            ->where(fn ($q) => $q->where('id', $search)
                ->orWhereHas('bl', fn ($bl) => $bl->where('bl', $search))
                ->orWhereHas('embarque', fn ($e) => $e->where('house_bl', $search)))
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->mapWithKeys(fn (FinCharges $c) => [$c->id => "#{$c->id} — {$c->embarque?->booking}".($c->bl ? " — BL {$c->bl->bl}" : '')])
            ->all();
    }

    private static function preview(mixed $id, bool $recalculated): HtmlString
    {
        $charge = $id ? FinCharges::find($id) : null;

        if (! $charge) {
            return new HtmlString('');
        }

        ['subject' => $subject, 'html' => $html] = app(PreviewInstructionEmail::class)($charge, $recalculated);

        // iframe sem scripts: o corpo é HTML livre do cadastro
        return new HtmlString('<p><strong>Assunto:</strong> '.e($subject).'</p><iframe sandbox="" srcdoc="'.e($html).'" style="width:100%;height:65vh;background:#fff"></iframe>');
    }
}
