<?php

namespace App\Filament\Resources\BrokerExpenses\Pages;

use App\Actions\Brokers\SaveBrokerExpense;
use App\Filament\Resources\BrokerExpenses\BrokerExpenseResource;
use App\Filament\Resources\Brokers\BrokerResource;
use App\Models\DespachantePorto;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;

class ManageBrokerExpenses extends ManageRecords
{
    protected static string $resource = BrokerExpenseResource::class;

    public int|string $brokerPort;

    public function mount(int|string $brokerPort = 0): void
    {
        $this->brokerPort = $brokerPort;

        parent::mount();
    }

    private function port(): DespachantePorto
    {
        return DespachantePorto::with(['despachante', 'porto'])->findOrFail($this->brokerPort);
    }

    public function getTitle(): string|Htmlable
    {
        $port = $this->port();

        return "Matriz de despesas — {$port->despachante->nome} (Porto: {$port->porto->nome})";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Voltar ao despachante')
                ->color('gray')
                ->url(fn () => BrokerResource::getUrl('view', ['record' => $this->port()->id_despachante])),
            CreateAction::make()
                ->label('Adicionar Despesa')
                ->using(fn (array $data) => app(SaveBrokerExpense::class)($this->port(), $data, auth()->user())),
        ];
    }
}
