<?php

namespace App\Filament\Livewire;

use DomainException;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Resultado de um relatório do Financeiro: roda a action e mostra a view só dentro deste componente, para o resultado não
 * entrar no estado (snapshot) da página a cada requisição. A página recria o componente a cada "Enviar" (chave nova).
 */
class ReportResult extends Component
{
    /** @var class-string */
    #[Locked]
    public string $action;

    #[Locked]
    public string $view;

    /** @var list<mixed> argumentos de `$action` */
    #[Locked]
    public array $arguments = [];

    /** @var array<string, mixed> variáveis extras da view */
    #[Locked]
    public array $viewData = [];

    /**
     * @param  class-string  $action
     * @param  list<mixed>  $arguments
     * @param  array<string, mixed>  $viewData
     */
    public function mount(string $action, string $view, array $arguments = [], array $viewData = []): void
    {
        $this->action = $action;
        $this->view = $view;
        $this->arguments = $arguments;
        $this->viewData = $viewData;
    }

    public function render(): View
    {
        try {
            $report = app($this->action)(...$this->arguments);
        } catch (DomainException $e) {
            Notification::make()
                ->title($e->getMessage())
                ->warning()
                ->send();

            $report = null;
        }

        return view('livewire.report-result', ['view' => $this->view, 'data' => ['report' => $report] + $this->viewData]);
    }
}
