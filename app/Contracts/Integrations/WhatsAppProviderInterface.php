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
     */
    public function sendText(string $to, string $text): bool;

    public function requiresConfig(): bool;

    public function configFields(): array;

    public function setConfig(array $config): void;
}
