<?php

namespace App\Services\Cloud;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MicrosoftProvider extends CloudBaseProvider
{
    public const PROVIDER = 'microsoft';

    protected const AUTH_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';

    protected const TOKEN_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';

    protected const GRAPH_URL = 'https://graph.microsoft.com/v1.0';

    protected const DRIVE_FOLDER = 'keystone';

    protected function scopes(string $scope): string
    {
        $base = ['openid', 'profile', 'email', 'offline_access', 'User.Read'];

        $scope === 'drive'
            ? $base[] = 'Files.ReadWrite'
            : $base[] = 'Calendars.ReadWrite';

        return $this->graphScopePrefix($base);
    }

    protected function graphScopePrefix(array $scopes): string
    {
        return implode(' ', array_map(function (string $s) {
            return Str::startsWith($s, ['openid', 'profile', 'email', 'offline_access']) ? $s : 'https://graph.microsoft.com/'.$s;
        }, $scopes));
    }

    public function authorizeUrl(string $state, string $scope): string
    {
        return static::AUTH_URL.'?'.http_build_query([
            'client_id' => $this->client['client_id'],
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => $this->scopes($scope),
            'response_mode' => 'query',
            'prompt' => 'select_account',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code): array
    {
        $tokens = $this->tokenRequest(static::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'scope' => $this->scopes($this->connection->scope),
        ]);

        $this->storeTokens($tokens);

        return [
            'email' => $this->accountEmail(),
            'expires_at' => $this->connection->token_expires_at,
        ];
    }

    protected function userinfoEndpoint(): string
    {
        return static::GRAPH_URL.'/me';
    }

    protected function userinfoField(): string
    {
        return 'mail';
    }

    public function refresh(): void
    {
        if (blank($this->connection->refresh_token)) {
            throw new \RuntimeException(__('Cloud connection has no refresh token. Reconnect the account.'));
        }

        $tokens = $this->tokenRequest(static::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) $this->connection->refresh_token,
            'scope' => $this->scopes($this->connection->scope),
        ]);

        $this->storeTokens($tokens);
    }

    public function createCalendarEvent(array $event): string
    {
        $response = Http::withToken($this->accessToken())
            ->timeout(30)
            ->post(static::GRAPH_URL.'/me/events', $this->calendarBody($event))
            ->throw();

        return $response->json('id');
    }

    public function updateCalendarEvent(string $eventId, array $event): void
    {
        Http::withToken($this->accessToken())
            ->timeout(30)
            ->patch(static::GRAPH_URL.'/me/events/'.rawurlencode($eventId), $this->calendarBody($event))
            ->throw();
    }

    public function deleteCalendarEvent(string $eventId): void
    {
        Http::withToken($this->accessToken())
            ->timeout(30)
            ->delete(static::GRAPH_URL.'/me/events/'.rawurlencode($eventId))
            ->throw();
    }

    protected function calendarBody(array $event): array
    {
        /** @var CarbonInterface $start */
        $start = $event['start'];
        $allDay = (bool) ($event['all_day'] ?? false);
        $end = $event['end'] ?? ($allDay ? $start->copy()->addDay() : $start->copy()->addMinutes($event['duration_minutes'] ?? 60));

        $tz = $start->timezone->getName();

        $body = [
            'subject' => $event['summary'],
            'body' => [
                'contentType' => 'text',
                'content' => (string) ($event['description'] ?? ''),
            ],
            'isAllDay' => $allDay,
            'start' => $allDay
                ? ['dateTime' => $start->format('Y-m-d'), 'timeZone' => 'UTC']
                : ['dateTime' => $start->format('Y-m-d\TH:i:s'), 'timeZone' => $tz],
            'end' => $allDay
                ? ['dateTime' => $end->format('Y-m-d'), 'timeZone' => 'UTC']
                : ['dateTime' => $end->format('Y-m-d\TH:i:s'), 'timeZone' => $tz],
        ];

        if (! empty($event['attendee_email'])) {
            $body['attendees'] = [[
                'emailAddress' => [
                    'address' => $event['attendee_email'],
                    'name' => $event['attendee_name'] ?? null,
                ],
                'type' => 'required',
            ]];
        }

        return $body;
    }

    public function uploadFile(string $path, string $name, string $mime): array
    {
        $uploadUrl = static::GRAPH_URL.'/me/drive/root:/'.self::DRIVE_FOLDER.'/'.rawurlencode($name).':/content';

        $response = Http::withToken($this->accessToken())
            ->withBody(file_get_contents($path), $mime)
            ->withHeaders(['Content-Length' => (string) filesize($path)])
            ->timeout(180)
            ->put($uploadUrl);

        if ($response->status() >= 400) {
            throw new \RuntimeException(__('OneDrive upload failed with status :status.', ['status' => $response->status()]));
        }

        $fileId = $response->json('id');
        $externalUrl = $response->json('webUrl');

        try {
            $link = Http::withToken($this->accessToken())
                ->timeout(30)
                ->post(static::GRAPH_URL.'/me/drive/items/'.rawurlencode((string) $fileId).'/createLink', [
                    'type' => 'view',
                    'scope' => 'anonymous',
                ])
                ->throw()
                ->json('link');

            $externalUrl = $link['webUrl'] ?? $externalUrl;
        } catch (\Throwable $e) {
            // Fall back to the item webUrl when sharing links are blocked.
        }

        return ['id' => $fileId, 'external_url' => $externalUrl];
    }

    public function fileDownloadUrl(string $fileId): string
    {
        return static::GRAPH_URL.'/me/drive/items/'.rawurlencode($fileId);
    }
}
