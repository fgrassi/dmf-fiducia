<?php

namespace App\Http\Controllers\Api;

use App\Actions\Cobranca\Boleto\ReceiveSicrediWebhook;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * POST /api/sicredi/webhook (api/sicredi/webhook do legado): liquidação do boleto. Responde 200 também para evento
 * repetido ou de boleto desconhecido, para a Sicredi não reenviar para sempre.
 *
 * Diferente do legado, que aceitava qualquer chamada: exige o segredo SICREDI_WEBHOOK_TOKEN (sem ele, uma chamada
 * forjada daria baixa em cobrança) e erro interno responde 500, para a Sicredi reenviar em vez de perder a liquidação.
 */
class SicrediWebhookController extends Controller
{
    public function __invoke(Request $request, ReceiveSicrediWebhook $receive): JsonResponse
    {
        $secret = trim((string) config('services.sicredi.webhook_token'));

        if ($secret === '') {
            logger()->error('{Sicredi Webhook} SICREDI_WEBHOOK_TOKEN não configurado');

            return response()->json(['message' => 'Webhook not configured'], 503);
        }

        if (! hash_equals($secret, (string) ($request->bearerToken() ?: $request->query('token')))) {
            logger()->error('{Sicredi Webhook} Token inválido');

            return response()->json(['message' => 'Invalid token'], 401);
        }

        $raw = $request->getContent();
        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid JSON'], 400);
        }

        try {
            $result = $receive($payload, $raw);
        } catch (InvalidArgumentException $e) {
            logger()->error('{Sicredi Webhook} '.$e->getMessage());

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'outcome' => 'erro', 'mensagem' => 'Internal error'], 500);
        }

        logger()->info("{Sicredi Webhook} outcome={$result['outcome']} id_evento={$result['id_evento']} ref_charge={$result['ref_charge']} msg={$result['mensagem']}");

        return response()->json($result);
    }
}
