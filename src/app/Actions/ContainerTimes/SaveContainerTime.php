<?php

namespace App\Actions\ContainerTimes;

use App\Actions\Booking\BookingLog;
use App\Enums\ContainerTime;
use App\Models\Embarque;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * executeSaveDemurrage / executeSaveDetention (legado): cadastra ou atualiza o container no controle da reserva e, em
 * seguida, revisa o controle da reserva inteira (ReviewContainerTimes).
 *
 * `data` traz container, ref_tipo_container, data_devolucao e, conforme o tipo: data_retirada e `file` (demurrage: a
 * retirada é sempre a chegada efetiva da reserva) ou data_retirada (detention). Um container só entra uma vez por reserva.
 *
 * Diferenças do legado: o demurrage novo já nasce com a descarga (o legado só preenchia ao editar) e o anexo só aceita
 * PDF, JPG e PNG, com a extensão tirada do conteúdo (o legado guardava o nome como veio, e `uploads` é servido por PHP).
 *
 * @throws DomainException
 */
class SaveContainerTime
{
    private const EXTENSIONS = ['pdf', 'jpg', 'png'];

    public function __construct(private ReviewContainerTimes $review, private BookingLog $log) {}

    /** @param array<string, mixed> $data */
    public function __invoke(ContainerTime $type, Embarque $booking, array $data, ?Model $record, int $userId): Model
    {
        $this->ensureValid($type, $booking, $data, $record);

        $record = DB::transaction(function () use ($type, $booking, $data, $record, $userId) {
            $record ??= $type->newRecord()->forceFill(['ref_embarque' => $booking->getKey(), 'created_at' => now()->toDateString(), 'created_by' => $userId]);

            $this->logFreeTimeChange($type, $record, $data, $userId);

            $record->forceFill([
                'container' => $data['container'],
                'ref_tipo_container' => $data['ref_tipo_container'],
                'data_devolucao' => $data['data_devolucao'] ?? null,
                ...($type === ContainerTime::Demurrage
                    ? ['data_retirada' => $booking->chegada_efetiva, 'free_time' => $data['free_time'] ?? null]
                    : ['data_retirada' => $data['data_retirada'] ?? null]),
                ...($record->exists ? ['updated_at' => now()->toDateString(), 'updated_by' => $userId] : []),
            ])->save();

            if (($data['file'] ?? null) instanceof UploadedFile) {
                $record->forceFill(['ref_file' => $this->store($record, $data['file'])])->save();
            }

            return $record;
        });

        ($this->review)($type, $booking->refresh());

        return $record;
    }

    /** executeEditFreeTime (legado): trocar um free time já informado deixa o antes/depois no rastreamento da reserva. */
    private function logFreeTimeChange(ContainerTime $type, Model $record, array $data, int $userId): void
    {
        $new = (int) ($data['free_time'] ?? 0);

        if ($type !== ContainerTime::Demurrage || ! $record->exists || ! $record->free_time || $new === (int) $record->free_time) {
            return;
        }

        $this->log->track($record->ref_embarque, 'Free Time Demurrage Modificado', "O Free Time da reserva foi modificado de {$record->free_time} para {$new}", $userId, 'x');
    }

    /** @throws DomainException */
    private function ensureValid(ContainerTime $type, Embarque $booking, array $data, ?Model $record): void
    {
        throw_if(
            $type === ContainerTime::Demurrage && filled($data['data_devolucao'] ?? null) && Carbon::parse($data['data_devolucao'])->gt(today()),
            DomainException::class,
            'A data máxima possível é o dia de hoje: '.today()->format('d/m/Y'),
        );

        $duplicate = $type->model()::where('ref_embarque', $booking->getKey())->where('container', $data['container'])->exists();

        throw_if(! $record && $duplicate, DomainException::class, 'Não foi possível adicionar o Container. Container já está cadastrado.');
    }

    private function store(Model $record, UploadedFile $file): string
    {
        $extension = strtolower((string) $file->guessExtension());

        throw_unless(in_array($extension, self::EXTENSIONS, true), DomainException::class, 'Tipo de arquivo não permitido. Envie PDF, JPG ou PNG.');

        $base = preg_replace('/[^A-Za-z0-9_-]/', '', str_replace(' ', '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)));
        $name = "demurrage_{$record->getKey()}_{$base}.{$extension}";

        Storage::disk('tracking_uploads')->putFileAs('demurrage', $file, $name);

        return $name;
    }
}
