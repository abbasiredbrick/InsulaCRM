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

    public function sendText(string $to, string $text): bool
    {
        Log::info('WhatsApp text (log driver)', ['to' => $to, 'text' => $text]);

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
