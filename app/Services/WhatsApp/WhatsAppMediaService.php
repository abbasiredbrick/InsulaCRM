<?php

namespace App\Services\WhatsApp;

use App\Contracts\Integrations\WhatsAppProviderInterface;
use App\Models\WhatsappConversation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Local persistence for WhatsApp media.
 *
 * Everything lives on the private `local` disk (storage/app/private) under
 * whatsapp/{tenant}/{conversation}/... and is streamed to the browser through
 * the authorized inbox media route — customer media is never exposed on a
 * public URL.
 */
class WhatsAppMediaService
{
    protected const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/heic' => 'heic',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'audio/ogg' => 'ogg',
        'audio/opus' => 'opus',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/aac' => 'aac',
        'audio/amr' => 'amr',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/plain' => 'txt',
    ];

    /**
     * Download an inbound webhook media payload and store it locally.
     *
     * @return array{path: string, name: string, mime: string, size: int}|null
     *                                                                         null when the download fails (webhook still records the message).
     */
    public function storeInbound(
        WhatsAppProviderInterface $provider,
        WhatsappConversation $conversation,
        array $media,
        ?string $wamid,
    ): ?array {
        $mediaId = (string) ($media['id'] ?? '');

        if ($mediaId === '') {
            return null;
        }

        $downloaded = $provider->downloadMedia($mediaId);

        if (! $downloaded) {
            return null;
        }

        $mime = (string) ($downloaded['mime_type'] ?: ($media['mime_type'] ?? ''));
        $ext = $this->extensionFor($mime, (string) ($downloaded['file_name'] ?: ($media['raw_file_name'] ?? '')));
        $key = $wamid ? preg_replace('/[^a-zA-Z0-9_.-]/', '', $wamid) : (string) Str::uuid();
        $dirname = sprintf('whatsapp/%d/%d', $conversation->tenant_id, $conversation->id);
        $path = $dirname.'/'.($key ?: Str::uuid()).'.'.$ext;

        Storage::disk('local')->put($path, $downloaded['bytes']);

        return [
            'path' => $path,
            'name' => (string) ($downloaded['file_name'] ?: 'whatsapp.'.$ext),
            'mime' => $mime ?: 'application/octet-stream',
            'size' => (int) strlen($downloaded['bytes']),
        ];
    }

    /**
     * Persist an agent-uploaded attachment from the composer.
     *
     * @return array{path: string, name: string, mime: string, size: int}
     */
    public function storeOutboundUpload(UploadedFile $file, WhatsappConversation $conversation): array
    {
        $dirname = sprintf('whatsapp/%d/%d', $conversation->tenant_id, $conversation->id);
        $source = $file->getRealPath();
        $size = (int) $file->getSize();

        if (! $source || ! is_file($source)) {
            throw new \RuntimeException('The uploaded media file could not be read.');
        }

        $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
        $ext = $this->extensionFor($mime, (string) $file->getClientOriginalName());
        $path = $dirname.'/'.Str::uuid().'.'.$ext;

        Storage::disk('local')->put($path, file_get_contents($source));

        return [
            'path' => $path,
            'name' => (string) ($file->getClientOriginalName() ?: 'attachment.'.$ext),
            'mime' => $mime ?: 'application/octet-stream',
            'size' => $size,
        ];
    }

    public function extensionFor(string $mime, string $originalName = ''): string
    {
        if ($mime !== '' && isset(self::EXTENSIONS[$mime])) {
            return self::EXTENSIONS[$mime];
        }

        if ($originalName !== '') {
            $fallback = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if (preg_match('/^[a-z0-9]{1,8}$/', $fallback)) {
                return $fallback;
            }
        }

        return 'bin';
    }
}
