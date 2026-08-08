<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The two images the platform owner controls: the login background and the logo.
 *
 * Both live on the `local` disk, which is storage/app/private — OUTSIDE the
 * document root. Nothing here is ever served by the web server directly; the
 * controller streams it with a content type we chose ourselves. An uploads
 * folder the server will happily execute is how image uploads turn into remote
 * code execution, and the simplest way not to have one is not to have one.
 */
class BrandingService
{
    /** The setting keys, and the only ones this service will touch. */
    public const ASSETS = ['login_background', 'logo'];

    private const DIRECTORY = 'branding';

    /** Both images as public URLs, ready for the SPA. Null means "unset". */
    public function urls(): array
    {
        $rows = PlatformSetting::whereIn('key', self::ASSETS)->pluck('value', 'key');

        return collect(self::ASSETS)
            ->mapWithKeys(fn (string $key) => [
                $key.'_url' => $rows->has($key) ? $this->urlFor($key, $rows[$key]) : null,
            ])
            ->all();
    }

    /** The stored path for one asset, or null. */
    public function path(string $key): ?string
    {
        return PlatformSetting::where('key', $key)->value('value');
    }

    /**
     * Replace an asset, returning the new set of URLs.
     *
     * The stored filename carries a random token, so the URL changes whenever
     * the image does. Without that, every browser and proxy that had cached the
     * old one would keep showing it and the owner would think the upload failed.
     */
    public function put(string $key, UploadedFile $file, ?string $actorEmail): array
    {
        $previous = $this->path($key);

        $name = sprintf('%s-%s.%s', str_replace('_', '-', $key), Str::random(24), $this->extensionFor($file));

        Storage::disk('local')->putFileAs(self::DIRECTORY, $file, $name);

        PlatformSetting::updateOrCreate(
            ['key' => $key],
            ['value' => self::DIRECTORY.'/'.$name, 'updated_by_email' => $actorEmail, 'updated_at' => now()],
        );

        // Only after the new one is safely written and recorded.
        if ($previous) {
            Storage::disk('local')->delete($previous);
        }

        return $this->urls();
    }

    /** Drop an asset and fall back to the built-in look. */
    public function forget(string $key): array
    {
        if ($path = $this->path($key)) {
            Storage::disk('local')->delete($path);
        }

        PlatformSetting::where('key', $key)->delete();

        return $this->urls();
    }

    /**
     * Trust the file, not the name.
     *
     * A browser will happily send "logo.php.png" or "logo.PNG"; the extension we
     * store is derived from the sniffed MIME type instead, and the validator has
     * already refused anything not on this list.
     */
    private function extensionFor(UploadedFile $file): string
    {
        return match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    private function urlFor(string $key, string $path): string
    {
        // The token in the filename is the cache-buster; passing it as a query
        // string keeps the route itself static and easy to reason about.
        return sprintf('/api/branding/%s?v=%s', str_replace('_', '-', $key), substr(sha1($path), 0, 12));
    }
}
