<?php

namespace App\Integrations\WhatsApp;

use App\Contracts\Integrations\WhatsAppProviderInterface;
use Illuminate\Support\Facades\Log;

class LogWhatsAppProvider implements WhatsAppProviderInterface
{
    protected array $config = [];

    public function driver(): string
    {
        return 'log';
    }

    public function name(): string
    {
        return 'Log (not configured)';
    }

    public function sendTemplate(
        string $to,
        string $template,
        array $bodyParameters,
        string $language,
        ?string $buttonParameter = null,
    ): bool {
        Log::info('WhatsApp template (log driver)', [
            'to' => $to,
            'template' => $template,
            'language' => $language,
            'body' => $bodyParameters,
            'button' => $buttonParameter,
        ]);

        return true;
    }

    public function sendText(string $to, string $text): ?string
    {
        Log::info('WhatsApp text (log driver)', ['to' => $to, 'text' => $text]);

        return 'log-'.bin2hex(random_bytes(8));
    }

    public function sendMedia(
        string $to,
        string $filePath,
        string $mediaType,
        ?string $caption = null,
        string $mime = '',
        string $fileName = '',
    ): ?string {
        Log::info('WhatsApp media (log driver)', [
            'to' => $to,
            'type' => $mediaType,
            'name' => $fileName ?: basename($filePath),
            'caption' => $caption,
            'mime' => $mime,
        ]);

        return 'log-'.bin2hex(random_bytes(8));
    }

    public function downloadMedia(string $mediaId): ?array
    {
        Log::info('WhatsApp media download (log driver)', ['media_id' => $mediaId]);

        return null;
    }

    public function markAsRead(string $to, string $wamid): bool
    {
        Log::info('WhatsApp mark-as-read (log driver)', ['to' => $to, 'wamid' => $wamid]);

        return true;
    }

    public function requiresConfig(): bool
    {
        return false;
    }

    public function configFields(): array
    {
        return [];
    }

    public function setConfig(array $config): void
    {
        $this->config = $config;
    }
}
