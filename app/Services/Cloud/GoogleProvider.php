<?php

namespace App\Services\Cloud;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleProvider extends CloudBaseProvider
{
    public const PROVIDER = 'google';

    protected const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    protected const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    protected const USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

    protected const CALENDAR_URL = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    protected const DRIVE_UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files';

    protected const DRIVE_FILES_URL = 'https://www.googleapis.com/drive/v3/files';

    protected function scopes(string $scope): string
    {
        $base = [
            'https://www.googleapis.com/auth/userinfo.email',
            'profile',
        ];

        $scope === 'drive'
            ? $base[] = 'https://www.googleapis.com/auth/drive.file'
            : $base[] = 'https://www.googleapis.com/auth/calendar.events';

        return implode(' ', $base);
    }

    public function authorizeUrl(string $state, string $scope): string
    {
        return static::AUTH_URL.'?'.http_build_query([
            'client_id' => $this->client['client_id'],
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => $this->scopes($scope),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code): array
    {
        $tokens = $this->tokenRequest(static::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
        ]);

        $this->storeTokens($tokens);

        return [
            'email' => $this->accountEmail(),
            'expires_at' => $this->connection->token_expires_at,
        ];
    }

    protected function userinfoEndpoint(): string
    {
        return static::USERINFO_URL;
    }

    public function refresh(): void
    {
        if (blank($this->connection->refresh_token)) {
            throw new \RuntimeException(__('Cloud connection has no refresh token. Reconnect the account.'));
        }

        $tokens = $this->tokenRequest(static::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) $this->connection->refresh_token,
        ]);

        $this->storeTokens($tokens);
    }

    public function createCalendarEvent(array $event): string
    {
        $response = Http::withToken($this->accessToken())
            ->timeout(30)
            ->post(static::CALENDAR_URL, $this->calendarBody($event))
            ->throw();

        return $response->json('id');
    }

    public function updateCalendarEvent(string $eventId, array $event): void
    {
        Http::withToken($this->accessToken())
            ->timeout(30)
            ->patch(static::CALENDAR_URL.'/'.rawurlencode($eventId), $this->calendarBody($event))
            ->throw();
    }

    public function deleteCalendarEvent(string $eventId): void
    {
        Http::withToken($this->accessToken())
            ->timeout(30)
            ->delete(static::CALENDAR_URL.'/'.rawurlencode($eventId))
            ->throw();
    }

    protected function calendarBody(array $event): array
    {
        /** @var CarbonInterface $start */
        $start = $event['start'];
        $allDay = (bool) ($event['all_day'] ?? false);
        $end = $event['end'] ?? ($allDay ? $start->copy()->addDay() : $start->copy()->addMinutes($event['duration_minutes'] ?? 60));

        $body = [
            'summary' => $event['summary'],
            'description' => $event['description'] ?? null,
        ];

        if ($allDay) {
            $body['start'] = ['date' => $start->format('Y-m-d')];
            $body['end'] = ['date' => $end->format('Y-m-d')];
        } else {
            $tz = $start->timezone->getName();
            $body['start'] = ['dateTime' => $start->format('Y-m-d\TH:i:s'), 'timeZone' => $tz];
            $body['end'] = ['dateTime' => $end->format('Y-m-d\TH:i:s'), 'timeZone' => $tz];
        }

        if (! empty($event['attendee_email'])) {
            $body['attendees'] = [[
                'email' => $event['attendee_email'],
                'displayName' => $event['attendee_name'] ?? null,
            ]];
        }

        return array_filter($body, fn ($v) => $v !== null);
    }

    public function uploadFile(string $path, string $name, string $mime): array
    {
        $folderId = $this->ensurePhotosFolder();

        $response = Http::withToken($this->accessToken())
            ->timeout(120)
            ->attach('metadata', json_encode([
                'name' => $name,
                'parents' => $folderId ? [$folderId] : [],
                'mimeType' => $mime,
            ], JSON_UNESCAPED_SLASHES), 'metadata.json')
            ->attach('data', file_get_contents($path), $name)
            ->post(static::DRIVE_UPLOAD_URL.'?uploadType=multipart&supportsAllDrives=true')
            ->throw();

        $fileId = $response->json('id');

        // Make view-by-link public so the URL can be used for portal feeds
        // and CRM display without proxies.
        try {
            Http::withToken($this->accessToken())
                ->post(static::DRIVE_FILES_URL.'/'.rawurlencode($fileId).'/permissions', [
                    'role' => 'reader',
                    'type' => 'anyone',
                    'allowFileDiscovery' => false,
                ])
                ->throw();
        } catch (\Throwable $e) {
            // Some drives forbid public links; keep the file private and
            // fall back to the signed metadata download URL.
        }

        $meta = Http::withToken($this->accessToken())
            ->get(static::DRIVE_FILES_URL.'/'.rawurlencode($fileId), ['fields' => 'id,webContentLink,webViewLink'])
            ->json();

        $externalUrl = $meta['webContentLink']
            ?? $meta['webViewLink']
            ?? 'https://drive.google.com/uc?export=view&id='.rawurlencode((string) $fileId);

        return ['id' => $fileId, 'external_url' => $externalUrl];
    }

    public function fileDownloadUrl(string $fileId): string
    {
        return 'https://drive.google.com/uc?export=view&id='.rawurlencode($fileId);
    }

    /**
     * Lazily create a per-tenant photo folder on the agent's drive and cache
     * its id on the connection.
     */
    protected function ensurePhotosFolder(): ?string
    {
        $folderId = $this->connection->metadata['photos_folder_id'] ?? null;
        if ($folderId) {
            return $folderId;
        }

        try {
            $response = Http::withToken($this->accessToken())
                ->timeout(30)
                ->post(static::DRIVE_FILES_URL, [
                    'name' => 'Keystone-'.Str::slug($this->connection->tenant->name ?? 'CRM'),
                    'mimeType' => 'application/vnd.google-apps.folder',
                ])
                ->throw();

            $folderId = $response->json('id');
        } catch (\Throwable $e) {
            $folderId = null;
        }

        $metadata = $this->connection->metadata ?? [];
        $metadata['photos_folder_id'] = $folderId;
        $this->connection->metadata = $metadata;
        $this->connection->save();

        return $folderId;
    }
}
