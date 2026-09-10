<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PortalIntegration;
use App\Services\Portals\PortalLeadService;
use App\Services\Portals\PortalPayloadNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PortalWebhookController extends Controller
{
    public function receive(Request $request, string $portal): JsonResponse
    {
        if (! in_array($portal, ['bayut', 'propertyfinder'], true)) {
            abort(404);
        }

        $integration = $this->resolveIntegration($request, $portal);

        if ($integration === null) {
            Log::warning('Portal webhook rejected', ['portal' => $portal]);

            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = json_decode($request->getContent(), true) ?: [];

        $lead = (new PortalLeadService)->createFromPayload(
            $integration,
            $this->portalLabel($portal, $request),
            (new PortalPayloadNormalizer)->normalize($portal, $data),
        );

        $integration->update([
            'last_synced_at' => now(),
            'last_error'     => null,
        ]);

        return response()->json([
            'status' => $lead ? 'ok' : 'ignored',
        ]);
    }

    protected function resolveIntegration(Request $request, string $portal): ?PortalIntegration
    {
        $candidates = PortalIntegration::where('portal', $portal)
            ->where('is_active', true)
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $signature = $request->header('X-dubizzle-Signature')
            ?? $request->header('X-Bayut-Signature')
            ?? $request->header('X-Hub-Signature-256')
            ?? null;

        if ($signature !== null) {
            foreach ($candidates as $candidate) {
                $secret = $candidate->webhook_secret;
                if ($secret !== null && $this->verify($signature, $secret, $request->getContent())) {
                    return $candidate;
                }
            }

            return null;
        }

        if ($candidates->count() === 1 && blank($candidates->first()->webhook_secret)) {
            return $candidates->first();
        }

        return null;
    }

    protected function verify(string $signature, string $secret, string $body): bool
    {
        $md5 = hash_equals(md5($secret . $body), $signature);
        if ($md5) {
            return true;
        }

        return hash_equals(hash_hmac('sha256', $body, $secret), $signature);
    }

    protected function portalLabel(string $portal, Request $request): string
    {
        if ($portal === 'bayut' && $request->hasHeader('X-dubizzle-Signature')) {
            return 'dubizzle';
        }

        return $portal;
    }
}