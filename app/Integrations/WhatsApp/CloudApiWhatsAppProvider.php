<?php

namespace App\Integrations\WhatsApp;

use App\Contracts\Integrations\WhatsAppProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudApiWhatsAppProvider implements WhatsAppProviderInterface
{
    protected array $config = [];

    public function driver(): string
    {
        return 'cloud-api';
    }

    public function name(): string
    {
        return 'WhatsApp Cloud API (Meta)';
    }

    public function sendTemplate(
        string $to,
        string $template,
        array $bodyParameters,
        string $language,
        ?string $buttonParameter = null,
    ): bool {
        $phoneNumberId = (string) ($this->config['phone_number_id'] ?? '');
        $accessToken = (string) ($this->config['access_token'] ?? '');
        $version = (string) ($this->config['api_version'] ?? 'v21.0');

        if ($phoneNumberId === '' || $accessToken === '') {
            Log::error('WhatsApp Cloud API: Missing configuration (phone_number_id or access_token).');

            return false;
        }

        $components = [];

        if (! empty($bodyParameters)) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn ($value) => ['type' => 'text', 'text' => (string) $value],
                    array_values($bodyParameters),
                ),
            ];
        }

        if (! empty($buttonParameter)) {
            $components[] = [
                'type' => 'button',
                'sub_type' => 'url',
                'index' => '0',
                'parameters' => [
                    ['type' => 'text', 'text' => (string) $buttonParameter],
                ],
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $language],
            ],
        ];

        if (! empty($components)) {
            $payload['template']['components'] = $components;
        }

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/messages", $payload);

            if ($response->successful()) {
                Log::info('WhatsApp template sent', [
                    'to' => $to,
                    'template' => $template,
                    'message_id' => $response->json('messages.0.id'),
                ]);

                return true;
            }

            Log::error('WhatsApp template failed', [
                'to' => $to,
                'template' => $template,
                'status' => $response->status(),
                'error' => $response->json('error.message', 'Unknown error'),
                'code' => $response->json('error.code'),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('WhatsApp template exception', [
                'to' => $to,
                'template' => $template,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function sendText(string $to, string $text): bool
    {
        $phoneNumberId = (string) ($this->config['phone_number_id'] ?? '');
        $accessToken = (string) ($this->config['access_token'] ?? '');
        $version = (string) ($this->config['api_version'] ?? 'v21.0');

        if ($phoneNumberId === '' || $accessToken === '') {
            Log::error('WhatsApp Cloud API: Missing configuration (phone_number_id or access_token).');

            return false;
        }

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'text',
                    'text' => ['preview_url' => false, 'body' => $text],
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::error('WhatsApp text failed', [
                'to' => $to,
                'status' => $response->status(),
                'error' => $response->json('error.message', 'Unknown error'),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('WhatsApp text exception', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function requiresConfig(): bool
    {
        return true;
    }

    public function configFields(): array
    {
        return [
            [
                'name' => 'phone_number_id',
                'label' => 'Phone Number ID',
                'type' => 'text',
                'placeholder' => '123456789012345',
                'required' => true,
                'hint' => 'Meta → your app → WhatsApp → API Setup. The numeric ID, not the phone number.',
            ],
            [
                'name' => 'access_token',
                'label' => 'Permanent Access Token',
                'type' => 'password',
                'placeholder' => '',
                'required' => true,
                'hint' => 'A System User token (never expires). Stored encrypted.',
            ],
            [
                'name' => 'business_account_id',
                'label' => 'WhatsApp Business Account ID',
                'type' => 'text',
                'placeholder' => '123456789012345',
                'required' => false,
                'hint' => 'Optional. The WABA ID, for reference.',
            ],
            [
                'name' => 'api_version',
                'label' => 'Graph API Version',
                'type' => 'text',
                'placeholder' => 'v21.0',
                'required' => false,
            ],
        ];
    }

    public function setConfig(array $config): void
    {
        $this->config = $config;
    }
}
