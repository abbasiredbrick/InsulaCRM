<?php

namespace App\Services\Cloud;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class CloudPhotoService
{
    public function __construct(protected CloudProviderFactory $factory) {}

    /**
     * Store an inventory/lead photo on the uploader's chosen storage.
     *
     * When the agent opted into their personal Drive/OneDrive and has a valid
     * drive connection, the file is pushed there and the provider URL is
     * returned. Otherwise it falls back to the workspace cloud (local/S3).
     *
     * @return array{path: ?string, external_url: ?string}
     */
    public function store(UploadedFile $file, string $directory, User $user): array
    {
        $preference = $user->photo_storage ?? 'cloud';

        if (in_array($preference, ['google', 'microsoft'], true)) {
            $connection = $user->driveConnections()->where('provider', $preference)->first();

            if ($connection) {
                try {
                    $provider = $this->factory->make($preference, $connection);
                    $result = $provider->uploadFile($file->getPathname(), $file->getClientOriginalName(), $file->getMimeType());

                    return ['path' => null, 'external_url' => $result['external_url'] ?? null];
                } catch (\Throwable $e) {
                    Log::warning('Drive photo upload failed, falling back to cloud storage', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $path = $file->store($directory, 'public');

        return ['path' => $path, 'external_url' => null];
    }
}
