<?php

namespace App\Http\Controllers;

use App\Http\Requests\BrandingRequest;
use App\Services\BrandingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Platform-wide branding: the login background and the logo.
 *
 * Reads are public because the login page needs them before anyone has signed
 * in. Writes are superadmin-only, enforced by the route group — a cafe admin
 * cannot restyle the whole platform.
 */
class BrandingController extends Controller
{
    public function __construct(private readonly BrandingService $branding) {}

    /** Public. What the SPA should render on the login screen and in the rail. */
    public function show(): JsonResponse
    {
        return response()->json($this->branding->urls());
    }

    /** Public. The image itself, streamed from outside the document root. */
    public function image(string $asset): SymfonyResponse
    {
        $key = str_replace('-', '_', $asset);

        if (! in_array($key, BrandingService::ASSETS, true)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $path = $this->branding->path($key);
        $disk = Storage::disk('local');

        if ($path === null || ! $disk->exists($path)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response($disk->get($path), 200, [
            'Content-Type' => $disk->mimeType($path),
            // nosniff so a browser cannot be talked into treating the bytes as
            // anything other than the image type we just declared.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            // The URL carries a token that changes with the file, so this is
            // safe to cache hard — and the login page is the first thing every
            // member of staff loads every morning.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /** Superadmin only. */
    public function store(BrandingRequest $request, string $asset): JsonResponse
    {
        $key = str_replace('-', '_', $asset);

        if (! in_array($key, BrandingService::ASSETS, true)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json(
            $this->branding->put($key, $request->file('image'), $request->user()?->email),
        );
    }

    /** Superadmin only. Puts the built-in look back. */
    public function destroy(Request $request, string $asset): JsonResponse
    {
        $key = str_replace('-', '_', $asset);

        if (! in_array($key, BrandingService::ASSETS, true)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json($this->branding->forget($key));
    }
}
