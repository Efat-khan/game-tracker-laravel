<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsService;
use App\Support\Tenancy\CafeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __construct(
        private readonly CafeContext $context,
        private readonly AnalyticsService $analytics,
    ) {}

    private function days(Request $request): int
    {
        return min(365, max(1, (int) $request->query('days', 30)));
    }

    private function limit(Request $request): int
    {
        return min(50, max(1, (int) $request->query('limit', 10)));
    }

    public function dailyIncome(Request $request): JsonResponse
    {
        return response()->json($this->analytics->dailyIncome($this->context->id(), $this->days($request)));
    }

    public function topStations(Request $request): JsonResponse
    {
        return response()->json(
            $this->analytics->topStations($this->context->id(), $this->days($request), $this->limit($request))
        );
    }

    public function topCustomers(Request $request): JsonResponse
    {
        return response()->json(
            $this->analytics->topCustomers($this->context->id(), $this->days($request), $this->limit($request))
        );
    }

    public function peakHours(Request $request): JsonResponse
    {
        return response()->json($this->analytics->peakHours($this->context->id(), $this->days($request)));
    }

    public function utilization(Request $request): JsonResponse
    {
        return response()->json($this->analytics->utilization($this->context->id(), $this->days($request)));
    }

    public function profit(Request $request): JsonResponse
    {
        return response()->json($this->analytics->profit($this->context->id(), $this->days($request)));
    }

    /** Admin only. */
    public function staff(Request $request): JsonResponse
    {
        return response()->json($this->analytics->staff($this->context->id(), $this->days($request)));
    }
}
