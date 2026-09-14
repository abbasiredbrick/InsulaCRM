<?php

namespace App\Services\Cloud;

use App\Models\User;
use App\Models\UserCloudConnection;
use RuntimeException;

class CloudProviderFactory
{
    public function forStart(string $provider, User $user, string $scope): CloudBaseProvider
    {
        $client = $this->clientFor($provider, $user);

        $connection = new UserCloudConnection([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'provider' => $provider,
            'scope' => $scope,
        ]);

        return $this->instanceFor($provider, $connection, $client);
    }

    public function make(string $provider, UserCloudConnection $connection): CloudBaseProvider
    {
        $client = $connection->tenant?->cloudClient($provider);

        if (! $client) {
            throw new RuntimeException(__('The :provider connection is no longer configured. Ask an admin to re-add its OAuth credentials.', [
                'provider' => ucfirst($provider),
            ]));
        }

        return $this->instanceFor($provider, $connection, $client);
    }

    protected function clientFor(string $provider, User $user): array
    {
        $client = $user->tenant?->cloudClient($provider);

        if (! $client) {
            throw new RuntimeException(__(':provider OAuth credentials are not configured by your workspace admin.', [
                'provider' => ucfirst($provider),
            ]));
        }

        return $client;
    }

    protected function instanceFor(string $provider, UserCloudConnection $connection, array $client): CloudBaseProvider
    {
        return match ($provider) {
            'google' => new GoogleProvider($connection, $client),
            'microsoft' => new MicrosoftProvider($connection, $client),
            default => throw new RuntimeException(__('Unknown cloud provider ":provider".', ['provider' => $provider])),
        };
    }
}
