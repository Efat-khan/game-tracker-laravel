<?php

namespace App\Http\Controllers;

use App\Http\Requests\SettingsRequest;
use App\Services\AuditService;
use App\Services\SettingsService;
use App\Support\Tenancy\CafeContext;
use Illuminate\Http\JsonResponse;

class SettingsController extends Controller
{
    public function __construct(
        private readonly CafeContext $context,
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json($this->settings->all($this->context->id()));
    }

    /** Admin only. */
    public function update(SettingsRequest $request): JsonResponse
    {
        $cafeId = $this->context->id();

        $values = $this->settings->put($cafeId, $request->validated());

        $this->audit->log('settings_update', 'settings', null, sprintf(
            'Settings changed — %s',
            collect($request->validated())->map(fn ($v, $k) => "{$k}={$v}")->implode(', '),
        ));

        return response()->json($values);
    }
}
