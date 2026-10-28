<?php

namespace App\Http\Controllers\Api;

use App\Actions\Cobranca\Nfse\ReceiveNfseWebhook;
use App\Http\Controllers\Controller;
use App\Models\ConfigNfse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/** POST /api/nfse/webhook (api/nfse/webhook do legado): callback nfse.status.updated do emissor de NFS-e. */
class NfseWebhookController extends Controller
{
    public function __invoke(Request $request, ReceiveNfseWebhook $receive): JsonResponse
    {
        $raw = $request->getContent();
        $secret = trim((string) ConfigNfse::current()->webhook_secret);

        // diferente do legado (que aceitava sem segredo): sem segredo configurado o endpoint recusa
        if ($secret === '') {
            logger()->error('{NFSe Webhook} webhook_secret não configurado');

            return response()->json(['message' => 'Webhook not configured'], 503);
        }

        if (! hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), (string) $request->header('X-Nfse-Signature'))) {
            logger()->error('{NFSe Webhook} Assinatura inválida');

            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid JSON'], 400);
        }

        $event = $payload['event'] ?? $request->header('X-Nfse-Event');

        if ($event && $event !== 'nfse.status.updated') {
            return response()->json(['message' => 'Unsupported event'], 400);
        }

        try {
            $nfse = $receive($payload, $raw);
        } catch (ModelNotFoundException $e) {
            logger()->error('{NFSe Webhook} '.$e->getMessage());

            return response()->json(['message' => $e->getMessage()], 404);
        } catch (InvalidArgumentException $e) {
            logger()->error('{NFSe Webhook} '.$e->getMessage());

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Internal error'], 500);
        }

        logger()->info("{NFSe Webhook} id_externo={$nfse->id_externo} status={$nfse->status}");

        return response()->json(['ok' => true, 'id_externo' => $nfse->id_externo, 'status' => $nfse->status]);
    }
}
