<?php

namespace App\Services\Cloud;

use App\Models\UserCloudConnection;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;

abstract class CloudBaseProvider
{
    public const PROVIDER = '';

    protected UserCloudConnection $connection;

    /** @var array{client_id:string, client_secret:string} */
    protected array $client;

    protected string $redirectUri;

    public function __construct(UserCloudConnection $connection, array $client)
    {
        $this->connection = $connection;
        $this->client = $client;
        $this->redirectUri = route('cloud.callback', ['provider' => static::PROVIDER]);
    }

    public function connection(): UserCloudConnection
    {
        return $this->connection;
    }

    /**
     * Authorize URL to send the agent to the provider's consent screen.
     */
    abstract public function authorizeUrl(string $state, string $scope): string;

    /**
     * Exchange an authorization code for tokens and persist them on the connection.
     *
     * @return array{email: ?string, expires_at: ?CarbonInterface}
     */
    abstract public function exchangeCode(string $code): array;

    /**
     * Refresh the stored tokens when they expired. Must persist the connection.
     */
    abstract public function refresh(): void;

    /**
     * Create a calendar event, returning the provider event id.
     */
    abstract public function createCalendarEvent(array $event): string;

    /**
     * Update an existing calendar event in place.
     */
    abstract public function updateCalendarEvent(string $eventId, array $event): void;

    /**
     * Delete a calendar event.
     */
    abstract public function deleteCalendarEvent(string $eventId): void;

    /**
     * Upload a local file to the agent's drive.
     *
     * @return array{id: string, external_url: ?string}
     */
    abstract public function uploadFile(string $path, string $name, string $mime): array;

    /**
     * Build a view/download URL for a previously uploaded file id.
     */
    abstract public function fileDownloadUrl(string $fileId): string;

    /**
     * The provider's email of the connected account.
     */
    abstract protected function userinfoEndpoint(): string;

    protected function userinfoField(): string
    {
        return 'email';
    }

    /**
     * Send an OAuth token request (code exchange or refresh).
     */
    protected function tokenRequest(string $endpoint, array $form): array
    {
        $response = Http::asForm()
            ->timeout(30)
            ->post($endpoint, array_merge([
                'client_id' => $this->client['client_id'],
                'client_secret' => $this->client['client_secret'],
            ], $form));

        if ($response->status() >= 400) {
            throw new \RuntimeException(__('Provider token request failed: :reason', [
                'reason' => $response->json('error') ?? $response->status(),
            ]));
        }

        return $response->json();
    }

    /**
     * Persist freshly obtained tokens on the connection.
     */
    protected function storeTokens(array $tokens): void
    {
        $this->connection->access_token = $tokens['access_token'];

        if (! empty($tokens['refresh_token'])) {
            $this->connection->refresh_token = $tokens['refresh_token'];
        }

        $this->connection->token_expires_at = ! empty($tokens['expires_in'])
            ? now()->addSeconds((int) $tokens['expires_in'])
            : null;

        $this->connection->save();
    }

    public function accessToken(): string
    {
        $this->ensureValidToken();

        return (string) $this->connection->access_token;
    }

    public function ensureValidToken(): void
    {
        if (! $this->connection->tokenIsValid()) {
            $this->refresh();
        }
    }

    public function accountEmail(): ?string
    {
        try {
            $json = Http::withToken($this->accessToken())
                ->timeout(20)
                ->get($this->userinfoEndpoint())
                ->throw()
                ->json();

            return $json[$this->userinfoField()] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Normalize an event payload into a start/end pair for this provider.
     */
    protected function eventStart(CarbonInterface $start, bool $allDay): string
    {
        return $allDay ? $start->format('Y-m-d') : $start->format('Y-m-d\TH:i:s');
    }
}
