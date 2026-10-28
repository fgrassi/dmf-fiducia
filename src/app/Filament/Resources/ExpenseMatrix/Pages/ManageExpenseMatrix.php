<?php

namespace App\Filament\Resources\ExpenseMatrix\Pages;

use App\Actions\Carriers\AddMatrixExpenses;
use App\Filament\Resources\Carriers\CarrierResource;
use App\Filament\Resources\ExpenseMatrix\ExpenseMatrixResource;
use App\Models\CmnArmadorPorto;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;

class ManageExpenseMatrix extends ManageRecords
{
    protected static string $resource = ExpenseMatrixResource::class;

    public int|string $armadorPorto;

    public function mount(int|string $armadorPorto = 0): void
    {
        $this->armadorPorto = $armadorPorto;

        parent::mount();
    }

    private function port(): CmnArmadorPorto
    {
        return CmnArmadorPorto::with(['armador', 'porto'])->findOrFail($this->armadorPorto);
    }

    public function getTitle(): string|Htmlable
    {
        $port = $this->port();

        return "Matriz de despesas — {$port->armador->nome} (Porto: {$port->porto->nome})";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Voltar ao armador')
                ->color('gray')
                ->url(fn () => CarrierResource::getUrl('view', ['record' => $this->port()->ref_armador])),
            CreateAction::make()
                ->label('Adicionar Despesa')
                ->using(fn (array $data) => app(AddMatrixExpenses::class)($this->port(), $data, auth()->user())[0]),
        ];
    }
}
