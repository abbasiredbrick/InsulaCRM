<?php

namespace App\Services\WhatsApp;

use App\Integrations\IntegrationManager;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Sends the "how did we do?" feedback template to a client on WhatsApp, from
 * the company number, when an agent asks for it on a lead (typically a
 * lost/dead one). This is deliberately manual-only: marketing templates cost
 * money and reach out cold, so an agent chooses when to send.
 *
 * The template must be approved in Meta first — its name, language and button
 * titles here MUST match the approved template, or Meta rejects the send
 * (error 132000/132001). The placeholders {{1}}/{{2}} map to the client's name
 * and the assigned agent's name, in that order. Meta rejects variables at the
 * very start or end of a body and dislikes many variables packed into a short
 * body, so the body opens and closes with literal text and carries only two
 * placeholders, well separated by unchanged copy.
 *
 * The sent message is mirrored into the WhatsApp Inbox so the client's reply
 * threads back into the conversation with the lead linked.
 */
class LeadFeedbackService
{
    public const TEMPLATE = 'lead_feedback_request';

    /**
     * Must match the approved template's language code EXACTLY — this template
     * is `en` (English), not `en_US`; a mismatched code is a send rejection.
     */
    public const LANGUAGE = 'en';

    /**
     * Quick-reply button titles. Keep these identical to the buttons on the
     * approved Meta template; they are what the client taps.
     *
     * @var array<int, string>
     */
    public const BUTTONS = [
        'Excellent service',
        'Could be better',
        'Prefer not to say',
    ];

    /**
     * The template body, with its ordered placeholders. Kept in one place so
     * the copy stored in the Inbox matches the copy Meta sends.
     *
     * It must begin and end with literal text (never a variable) and stay at
     * two variables — Meta rejects a leading/trailing variable and a high
     * variable-to-length ratio on a short body.
     */
    public const BODY = 'Thank you for considering us, {{1}}. Did we understand your requirements? Did our agent {{2}} provide the best possible service? Is there anything we can improve? And what was the main reason you decided not to proceed? Please tap an option below or reply in your own words.';

    public function __construct(
        protected IntegrationManager $integrationManager,
        protected WhatsAppConversationService $conversationService,
    ) {}

    /**
     * @return array{success: bool, message: string}
     */
    public function request(Lead $lead, User $actor): array
    {
        if ($lead->do_not_contact) {
            return $this->fail(__('This lead is marked do-not-contact.'));
        }

        $to = $lead->whatsapp_phone;

        if (! $to) {
            return $this->fail(__('This lead has no usable phone number.'));
        }

        $provider = $this->integrationManager->getWhatsAppProvider($lead->tenant_id);

        if ($provider->driver() === 'log') {
            return $this->fail(__('WhatsApp is not configured for this company.'));
        }

        $parameters = $this->bodyParameters($lead);

        try {
            $sent = $provider->sendTemplate(
                $to,
                self::TEMPLATE,
                $parameters,
                self::LANGUAGE,
            );
        } catch (\Throwable $e) {
            Log::error("Lead feedback template send failed for lead #{$lead->id}: {$e->getMessage()}");

            return $this->fail(__('WhatsApp could not send the message. Check the WhatsApp integration configuration.'));
        }

        if (! $sent) {
            return $this->fail(__('WhatsApp could not send the message. Check the WhatsApp integration configuration.'));
        }

        $this->recordInInbox($lead, $actor, $parameters);

        AuditLog::log('lead.feedback_requested', $lead, null, [
            'template' => self::TEMPLATE,
            'to' => $to,
        ]);

        return [
            'success' => true,
            'message' => __('Feedback request sent to :name.', ['name' => $lead->full_name ?: $to]),
        ];
    }

    /**
     * The ordered body parameters for {{1}} and {{2}}: client name, agent name.
     *
     * @return array<int, string>
     */
    protected function bodyParameters(Lead $lead): array
    {
        $clientName = trim((string) $lead->first_name) ?: ($lead->full_name ?: __('there'));
        $agentName = $lead->agent?->name ?: __('our team');

        return [$clientName, $agentName];
    }

    /**
     * Mirror the outbound template into the Inbox so the client's reply lands
     * in a conversation linked to the lead.
     *
     * @param  array<int, string>  $parameters
     */
    protected function recordInInbox(Lead $lead, User $actor, array $parameters): void
    {
        $waId = preg_replace('/[^0-9]/', '', (string) $lead->whatsapp_phone) ?? '';

        if ($waId === '') {
            return;
        }

        try {
            $conversation = $this->conversationService->findOrCreateConversation(
                $lead->tenant_id,
                $waId,
                $lead,
            );

            $this->conversationService->addOutboundMessage(
                $conversation,
                $actor->id,
                $this->renderBody($parameters),
                null,
                ['type' => 'template', 'template' => self::TEMPLATE],
            );
        } catch (\Throwable $e) {
            // The message already left; a mirroring failure must not surface as
            // a failed request.
            Log::error("Lead feedback inbox mirror failed for lead #{$lead->id}: {$e->getMessage()}");
        }
    }

    /**
     * @param  array<int, string>  $parameters
     */
    protected function renderBody(array $parameters): string
    {
        return str_replace(
            ['{{1}}', '{{2}}'],
            $parameters,
            self::BODY,
        );
    }

    /**
     * @return array{success: bool, message: string}
     */
    protected function fail(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
