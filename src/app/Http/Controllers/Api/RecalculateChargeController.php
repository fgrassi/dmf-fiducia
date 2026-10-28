<?php

namespace App\Http\Controllers\Api;

use App\Actions\Cobranca\Recalculation\RecalculateChargeByService;
use App\Http\Controllers\Controller;
use App\Models\FinCharges;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** cron/recalculaCobranca?id= (legado): chamado pelo job UpdateCharge da intranet, que lê `message` e `error` da resposta. */
class RecalculateChargeController extends Controller
{
    public function __invoke(Request $request, RecalculateChargeByService $recalculate): JsonResponse
    {
        // diferente do legado (endpoint aberto): exige o token Bearer, e sem token configurado recusa
        $token = (string) config('services.cobranca.recalculation_token');

        if ($token === '') {
            return response()->json(['message' => 'Recalculation not configured', 'error' => 'Recalculation not configured'], 503);
        }

        if (! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Unauthorized', 'error' => 'Unauthorized'], 401);
        }

        $charge = FinCharges::find($request->input('id'));

        if (! $charge) {
            return response()->json(['message' => 'Erro while updating the Invoice', 'error' => 'Invoice not found'], 404);
        }

        try {
            $recalculate($charge);
        } catch (DomainException $e) {
            return response()->json(['message' => 'Erro while updating the Invoice', 'error' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Invoice updated successfully', 'error' => '']);
    }
}
