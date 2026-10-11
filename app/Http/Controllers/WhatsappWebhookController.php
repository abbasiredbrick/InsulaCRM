<?php

namespace App\Http\Controllers;

use App\Models\Integration;
use App\Services\WhatsApp\WhatsAppConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Meta WhatsApp Cloud API webhook.
 *
 * Both endpoints are public: Meta calls them without a session, so the tenant
 * is resolved from the payload's phone_number_id (POST) or matched against the
 * configured verify tokens (GET). Nothing here may rely on auth().
 */
class WhatsappWebhookController extends Controller
{
    public function __construct(
        protected WhatsAppConversationService $conversationService
    ) {}

    public function verify(Request $request)
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token && $this->isKnownVerifyToken((string) $token)) {
            return response((string) $challenge, 200);
        }

        return response('Forbidden', 403);
    }

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? '') !== 'messages' || empty($change['value'])) {
                    continue;
                }

                $this->processValue($change['value'], $request);
            }
        }

        return response()->json(['status' => 'ok']);
    }

    protected function processValue(array $value, Request $request): void
    {
        $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');
        $integration = $this->integrationForPhoneNumber($phoneNumberId);

        if (! $integration) {
            Log::warning('WhatsApp webhook: no integration for phone_number_id', [
                'phone_number_id' => $phoneNumberId,
            ]);

            return;
        }

        if (! $this->signatureIsValid($integration, $request)) {
            abort(403, 'Invalid signature');
        }

        // Give the (unauthenticated) request a tenant so any tenant-scoped
        // model writes resolve to the right company.
        $request->attributes->set('tenant', $integration->tenant);

        $waId = $value['contacts'][0]['wa_id'] ?? null;

        foreach ($value['messages'] ?? [] as $message) {
            $from = $message['from'] ?? $waId;

            if (! $from) {
                continue;
            }

            $body = $this->messageBody($message);

            if ($body === null) {
                continue;
            }

            $lead = $this->conversationService->findLeadByWaId($integration->tenant_id, (string) $from);
            $conversation = $this->conversationService->findOrCreateConversation(
                $integration->tenant_id,
                (string) $from,
                $lead,
            );

            $this->conversationService->addInboundMessage(
                $conversation,
                $body,
                $message['id'] ?? null,
                $value,
            );
        }
    }

    /**
     * Flatten an inbound message to displayable text, or null for message types
     * the inbox does not surface (reactions, delivery/read status, ...).
     */
    protected function messageBody(array $message): ?string
    {
        return match ($message['type'] ?? '') {
            'text' => $message['text']['body'] ?? null,
            'button' => $message['button']['text'] ?? null,
            'interactive' => $message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title']
                ?? null,
            default => null,
        };
    }

    protected function integrationForPhoneNumber(string $phoneNumberId): ?Integration
    {
        if ($phoneNumberId === '') {
            return null;
        }

        return Integration::withoutGlobalScopes()
            ->where('category', 'whatsapp')
            ->where('driver', 'cloud-api')
            ->where('is_active', true)
            ->get()
            ->first(fn (Integration $integration) => (string) ($integration->config['phone_number_id'] ?? '') === $phoneNumberId);
    }

    protected function isKnownVerifyToken(string $token): bool
    {
        $configured = config('services.whatsapp.verify_token');

        if ($configured && hash_equals((string) $configured, $token)) {
            return true;
        }

        return Integration::withoutGlobalScopes()
            ->where('category', 'whatsapp')
            ->where('driver', 'cloud-api')
            ->where('is_active', true)
            ->get()
            ->contains(fn (Integration $integration) => (string) ($integration->config['verify_token'] ?? '') !== ''
                && hash_equals((string) $integration->config['verify_token'], $token));
    }

    protected function signatureIsValid(Integration $integration, Request $request): bool
    {
        $appSecret = (string) ($integration->config['app_secret'] ?? config('services.whatsapp.app_secret') ?? '');

        if ($appSecret === '') {
            return true;
        }

        $signature = $request->header('X-Hub-Signature-256');

        if (! $signature) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);

        return hash_equals($expected, $signature);
    }
}
