<?php

namespace App\Filament\Resources\Bls\Pages\Concerns;

use App\Actions\Booking\Bls\BlContainerRules;
use App\Actions\Booking\Bls\BlWarnings;
use App\Actions\Booking\Bls\SaveBl;
use App\Filament\Resources\Embarques\EmbarqueResource;
use App\Filament\Resources\Embarques\RelationManagers\BlsRelationManager;
use App\Filament\Support\BlForm;
use App\Models\Bl;
use App\Models\Embarque;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** O que as páginas de criar e editar BL têm em comum: o formulário, o salvar e os avisos de depois. */
trait ManagesBl
{
    /** Estado só da tela (linha de entrada de container e seletores): nunca vai para o BL. */
    private const SCREEN_ONLY_FIELDS = ['container_entry', 'container_keep', 'container_editing', 'saved_shipper', 'saved_consignee'];

    /** "Salvar e Finalizar": além de salvar, aprova o BL e manda a cópia aos contatos. */
    public bool $finalize = false;

    public function form(Schema $schema): Schema
    {
        return $schema->components(BlForm::components($this->booking()));
    }

    protected function booking(): Embarque
    {
        return $this->getParentRecord();
    }

    /** Volta para a aba Aprovação BL da reserva (listAprovacaoBl, legado). */
    protected function listUrl(): string
    {
        $tab = collect(EmbarqueResource::getRelations())->search(BlsRelationManager::class);

        return EmbarqueResource::getUrl('view', ['record' => $this->booking(), 'relation' => $tab]);
    }

    /** A regra que barra o BL (temperatura reefer sem valor) volta ao formulário; o que só avisa aparece depois de salvar. */
    protected function saveBl(?Bl $bl, array $data): Bl
    {
        try {
            $bl = app(SaveBl::class)($this->booking(), $bl, Arr::except($data, self::SCREEN_ONLY_FIELDS), auth()->id(), $this->finalize);
        } catch (DomainException $e) {
            $this->finalize = false;

            Notification::make()
                ->title($e->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }

        foreach (app(BlWarnings::class)($this->booking(), $bl) as $warning) {
            Notification::make()
                ->title('Atenção')
                ->body($warning)
                ->warning()
                ->persistent()
                ->send();
        }

        return $bl;
    }

    protected function finalizeAction(string $method): Action
    {
        return Action::make('salvarEFinalizar')
            ->label('Salvar e Finalizar')
            ->icon(Heroicon::OutlinedEnvelope)
            ->action($method);
    }

    protected function cancelAction(): Action
    {
        return Action::make('cancelar')
            ->label('Cancelar')
            ->color('gray')
            ->url($this->listUrl());
    }

    /** Largura toda: a linha de entrada e a lista de containers precisam de espaço. */
    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    /** Adicionar / Salvar do container (container_submit act=2, legado): valida a linha e a põe na lista. */
    public function addContainer(): void
    {
        if (blank($this->data['bl'] ?? null)) {
            throw ValidationException::withMessages(['data.bl' => 'É necessário informar o número do BL!']);
        }

        $entry = $this->data['container_entry'] ?? [];
        $validator = BlContainerRules::validator($entry, $this->booking()->impor_expor === 'e');

        if ($validator->fails()) {
            throw ValidationException::withMessages(collect($validator->errors()->messages())->mapWithKeys(fn (array $messages, string $field) => ["data.container_entry.{$field}" => $messages])->all());
        }

        $containers = $this->data['containers'] ?? [];
        $key = filled($this->data['container_editing'] ?? null) && isset($containers[$this->data['container_editing']]) ? $this->data['container_editing'] : (string) Str::uuid();

        $containers[$key] = [
            ...Arr::only($entry, [...BlForm::COPIED_FIELDS, 'numero', 'tara', 'seals_n1', 'seals_n2', 'seals_n3']),
            'container_id' => $containers[$key]['container_id'] ?? null,
        ];

        $this->data['containers'] = $containers;
        $this->clearContainerEntry();
        $this->fillContainerTotals();
    }

    /** O lápis da lista: leva o container para a linha de entrada. */
    public function editContainer(string $key): void
    {
        $this->data['container_entry'] = Arr::only($this->data['containers'][$key] ?? [], [...BlForm::COPIED_FIELDS, 'numero', 'tara', 'seals_n1', 'seals_n2', 'seals_n3']);
        $this->data['container_editing'] = $key;
    }

    /** O ícone de copiar: só tipo, temperatura, quantidade, pesos e volume vão para a linha; vira um container novo. */
    public function copyContainer(string $key): void
    {
        $this->data['container_entry'] = Arr::only($this->data['containers'][$key] ?? [], BlForm::COPIED_FIELDS);
        $this->data['container_editing'] = null;
    }

    public function removeContainer(string $key): void
    {
        unset($this->data['containers'][$key]);

        if (($this->data['container_editing'] ?? null) === $key) {
            $this->clearContainerEntry();
        }

        $this->fillContainerTotals();
    }

    /** Cancelar (container_submit act=4): limpa a linha de entrada. */
    public function cancelContainerEdit(): void
    {
        $this->clearContainerEntry();
    }

    /** O que sobra na linha depois de adicionar ou cancelar: com "Manter valores", tipo, quantidade, pesos e volume ficam. */
    private function clearContainerEntry(): void
    {
        $this->data['container_entry'] = ($this->data['container_keep'] ?? false)
            ? Arr::only($this->data['container_entry'] ?? [], BlForm::COPIED_FIELDS)
            : [];
        $this->data['container_editing'] = null;
    }

    private function fillContainerTotals(): void
    {
        foreach (BlForm::totals($this->data['containers'] ?? []) as $field => $total) {
            $this->data[$field] = $total;
        }
    }

    public function getRedirectUrl(): string
    {
        return $this->listUrl();
    }
}
