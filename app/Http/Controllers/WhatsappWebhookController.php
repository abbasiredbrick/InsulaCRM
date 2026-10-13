<?php

namespace App\Http\Controllers;

use App\Integrations\IntegrationManager;
use App\Models\Integration;
use App\Models\WhatsappConversation;
use App\Services\WhatsApp\WhatsAppConversationService;
use App\Services\WhatsApp\WhatsAppMediaService;
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
    protected const MEDIA_TYPES = ['image', 'video', 'audio', 'document', 'sticker'];

    public function __construct(
        protected WhatsAppConversationService $conversationService,
        protected WhatsAppMediaService $mediaService,
        protected IntegrationManager $integrationManager,
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

        Log::debug('WhatsApp webhook POST received', [
            'entries' => count($payload['entry'] ?? []),
            'has_signature' => $request->hasHeader('X-Hub-Signature-256'),
        ]);

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
        $profileName = $value['contacts'][0]['profile']['name'] ?? null;

        $this->applyStatuses($integration->tenant_id, $value);

        foreach ($value['messages'] ?? [] as $message) {
            $from = $message['from'] ?? $waId;

            if (! $from) {
                continue;
            }

            $lead = $this->conversationService->findLeadByWaId($integration->tenant_id, (string) $from);
            $conversation = $this->conversationService->findOrCreateConversation(
                $integration->tenant_id,
                (string) $from,
                $lead,
                (string) $profileName,
            );

            $this->ingestMessage($conversation, $message, $integration);
        }
    }

    protected function ingestMessage(WhatsappConversation $conversation, array $message, Integration $integration): void
    {
        $type = $message['type'] ?? '';
        $wamid = $message['id'] ?? null;
        $meta = $message;

        if ($type === 'text') {
            $this->conversationService->addInboundMessage(
                $conversation,
                (string) ($message['text']['body'] ?? ''),
                (string) $wamid,
                $meta,
            );

            return;
        }

        // A tapped quick-reply button on a template, e.g. the feedback request.
        // The label is what the client sees; the payload carries the button id.
        if ($type === 'button') {
            $title = (string) ($message['button']['text'] ?? '');
            $payload = (string) ($message['button']['payload'] ?? '');

            $this->conversationService->addInboundMessage(
                $conversation,
                $title !== '' ? $title : $payload,
                (string) $wamid,
                [...$meta, 'button_payload' => $payload],
            );

            return;
        }

        // A reply from an interactive (non-template) button or list message.
        if ($type === 'interactive') {
            $interactive = (array) ($message['interactive'] ?? []);
            $reply = (array) ($interactive['button_reply'] ?? $interactive['list_reply'] ?? []);

            $this->conversationService->addInboundMessage(
                $conversation,
                (string) ($reply['title'] ?? ''),
                (string) $wamid,
                [...$meta, 'reply_id' => (string) ($reply['id'] ?? '')],
            );

            return;
        }

        if (in_array($type, self::MEDIA_TYPES, true)) {
            $payload = (array) ($message[$type] ?? []);
            $mediaId = (string) ($payload['id'] ?? '');
            $caption = (string) ($payload['caption'] ?? '');

            if ($type === 'sticker') {
                $caption = '';
            }

            if ($mediaId === '') {
                $media = [];
            } else {
                $provider = $this->integrationManager->getWhatsAppProvider($integration->tenant_id);
                $media = $this->mediaService->storeInbound(
                    $provider,
                    $conversation,
                    [...$payload, 'raw_file_name' => (string) ($payload['filename'] ?? '')],
                    (string) $wamid,
                ) ?? [];
            }

            $this->conversationService->addInboundMediaMessage(
                $conversation,
                $type,
                $caption,
                $media,
                (string) $wamid,
                $meta,
            );

            return;
        }

        if ($type === 'location') {
            $location = (array) ($message['location'] ?? []);
            $name = (string) ($location['name'] ?? '');
            $address = (string) ($location['address'] ?? '');
            $body = trim($name."\n".$address);

            $this->conversationService->addInboundMediaMessage(
                $conversation,
                'location',
                $body,
                [],
                (string) $wamid,
                [...$meta, 'latitude' => $location['latitude'] ?? null, 'longitude' => $location['longitude'] ?? null],
            );

            return;
        }

        if ($type === 'contacts') {
            $lines = [];

            foreach ((array) ($message['contacts'] ?? []) as $contact) {
                $contact = (array) $contact;
                $name = (string) ($contact['name']['formatted_name'] ?? '');
                $phones = array_column((array) ($contact['phones'] ?? []), 'phone');

                $line = $name;
                if ($phones) {
                    $line .= ' · '.implode(', ', array_map('trim', $phones));
                }
                $lines[] = $line;
            }

            if ($lines) {
                $this->conversationService->addInboundMediaMessage(
                    $conversation,
                    'contact',
                    implode("\n", $lines),
                    [],
                    (string) $wamid,
                    $meta,
                );
            }
        }
    }

    /**
     * Apply delivery receipts (sent/delivered/read) to outbound messages.
     * Statuses arrive in the same value block, sometimes without any message.
     */
    protected function applyStatuses(int $tenantId, array $value): void
    {
        foreach ($value['statuses'] ?? [] as $status) {
            $recipient = (string) ($status['recipient_id'] ?? '');

            if ($recipient === '') {
                continue;
            }

            $conversation = WhatsappConversation::where('tenant_id', $tenantId)
                ->where('wa_id', preg_replace('/[^0-9]/', '', $recipient))
                ->first();

            if (! $conversation) {
                continue;
            }

            $this->conversationService->recordDelivery(
                $conversation,
                (string) ($status['id'] ?? null),
                (string) ($status['status'] ?? ''),
            );
        }
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
            Log::warning('WhatsApp webhook signature missing; rejecting', [
                'phone_number_id' => $request->input('entry.0.changes.0.value.metadata.phone_number_id'),
            ]);

            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);

        $valid = hash_equals($expected, $signature);

        if (! $valid) {
            Log::warning('WhatsApp webhook signature mismatch; rejecting', [
                'expected' => $expected,
                'received' => $signature,
            ]);
        }

        return $valid;
    }
}
