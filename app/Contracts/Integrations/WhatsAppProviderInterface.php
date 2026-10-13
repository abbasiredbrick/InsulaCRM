<?php

namespace App\Contracts\Integrations;

interface WhatsAppProviderInterface
{
    public function driver(): string;

    public function name(): string;

    /**
     * Send a pre-approved template message.
     *
     * @param  string  $to  digits-only international number (no leading +)
     * @param  array<int, string>  $bodyParameters  values for the template body placeholders
     * @param  string|null  $buttonParameter  value injected into a dynamic URL button, if any
     */
    public function sendTemplate(
        string $to,
        string $template,
        array $bodyParameters,
        string $language,
        ?string $buttonParameter = null,
    ): bool;

    /**
     * Send a free-form text message. Only valid inside a 24h customer service window.
     *
     * @return string|null the provider's message id (wamid) on success, null on failure
     */
    public function sendText(string $to, string $text): ?string;

    /**
     * Send a media message (image/video/audio/document) from a local file.
     *
     * @return string|null the provider's message id (wamid) on success, null on failure
     */
    public function sendMedia(
        string $to,
        string $filePath,
        string $mediaType,
        ?string $caption = null,
        string $mime = '',
        string $fileName = '',
    ): ?string;

    /**
     * Download an inbound media payload returned by the webhook.
     *
     * @return array{bytes: string, mime_type: string, file_name: string}|null
     */
    public function downloadMedia(string $mediaId): ?array;

    /**
     * Tell Meta the customer's last message was read (clears the "read" tick
     * on their side for our conversation).
     */
    public function markAsRead(string $to, string $wamid): bool;

    public function requiresConfig(): bool;

    public function configFields(): array;

    public function setConfig(array $config): void;
}
