<?php

namespace App\Filament\Resources\InvoiceControls\Pages;

use App\Enums\Cobranca\InvoiceControlStatus;
use App\Filament\Resources\InvoiceCarriers\InvoiceCarrierResource;
use App\Filament\Resources\InvoiceControls\InvoiceControlResource;
use App\Models\ControleInvoiceMaster;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListInvoiceControls extends ListRecords
{
    protected static string $resource = InvoiceControlResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('armadores')
                ->label('Armadores com controle')
                ->icon('heroicon-o-truck')
                ->color('gray')
                ->url(InvoiceCarrierResource::getUrl()),
        ];
    }

    /** Abas do legado: Pendentes / OK / Liberados (aguardando arquivos), com o contador respeitando os filtros (contarPorStatus). */
    public function getTabs(): array
    {
        $tab = fn (string $label, InvoiceControlStatus $status) => Tab::make($label)
            ->badge(fn () => $this->filterTableQuery(ControleInvoiceMaster::query())->where('status', $status)->count())
            ->badgeColor($status->getColor())
            ->modifyQueryUsing(fn ($query) => $query->where('status', $status));

        return [
            'pendentes' => $tab('Pendentes', InvoiceControlStatus::Pending),
            'ok' => $tab('OK', InvoiceControlStatus::Ok),
            'liberados' => $tab('Liberados (aguardando arquivos)', InvoiceControlStatus::ReleasedAwaitingFile),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'pendentes';
    }
}
