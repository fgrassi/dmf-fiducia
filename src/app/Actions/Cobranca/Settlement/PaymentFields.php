<?php

namespace App\Actions\Cobranca\Settlement;

use App\Models\FinCharges;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;

/** Campos de pagamento do FinChargesForm (legado), comuns às baixas bancária e por boleto. */
class PaymentFields
{
    private const MONEY = ['received_value' => 'Valor Recebido', 'interest' => 'Juros', 'fine' => 'Multa', 'discount' => 'Desconto', 'tax_boleto' => 'Taxa'];

    /** sfNumberPtBrValidator + datas: devolve as colunas de fin_charges prontas para gravar. */
    public static function parse(array $data): array
    {
        if (blank($data['ref_bank'] ?? null)) {
            throw new DomainException('Informe o banco.');
        }

        $values = [];

        foreach (self::MONEY as $field => $label) {
            $raw = trim((string) ($data[$field] ?? ''));

            if ($raw === '' && $field !== 'received_value') {
                $values[$field] = null;

                continue;
            }

            $number = str_replace(',', '.', str_replace('.', '', $raw));

            if (! is_numeric($number)) {
                throw new DomainException("{$label}: \"{$raw}\" não é um número.");
            }

            $values[$field] = round((float) $number, 2);
        }

        return [
            ...$values,
            'ref_bank' => (int) $data['ref_bank'],
            'payment_date' => CarbonImmutable::parse($data['payment_date'])->toDateString(),
            'payment_confirmation' => CarbonImmutable::parse($data['payment_confirmation'])->toDateString(),
            'discount_obs' => $data['discount_obs'] ?? null,
            'escritorio_id' => filled($data['escritorio_id'] ?? null) ? (int) $data['escritorio_id'] : null,
            'autoriza_liberacao_embarque' => ! empty($data['autoriza_liberacao_embarque']) ? 'on' : null,
        ];
    }

    /** Liberação do embarque autorizada e ainda não enviada (status_envio 1): marca para envio. */
    public static function releaseShipment(FinCharges $charge, User $user): void
    {
        if ($charge->autoriza_liberacao_embarque === 'on' && (int) $charge->status_envio !== 1) {
            $charge->forceFill([
                'status_envio' => 0,
                'user' => $user->getFilamentName(),
                'hora' => now()->format('H:i:s'),
                'data_envio' => now()->toDateString(),
            ]);
        }
    }
}
