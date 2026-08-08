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

    /**
     * Admin only. The date defaults to today; anything unparseable falls back
     * to today rather than 500ing on a typo in a query string.
     */
    public function dailySummary(Request $request): JsonResponse
    {
        return response()->json(
            $this->analytics->dailySummary($this->context->id(), $this->date($request))
        );
    }

    /** Admin only. */
    public function monthlySummary(Request $request): JsonResponse
    {
        return response()->json(
            $this->analytics->monthlySummary($this->context->id(), $this->month($request))
        );
    }

    private function date(Request $request): string
    {
        $value = (string) $request->query('date', '');

        return preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value) === 1 && strtotime($value) !== false
            ? $value
            : now()->format('Y-m-d');
    }

    private function month(Request $request): string
    {
        $value = (string) $request->query('month', '');

        return preg_match('/^\\d{4}-(0[1-9]|1[0-2])$/', $value) === 1
            ? $value
            : now()->format('Y-m');
    }

    /** Admin only. */
    public function staff(Request $request): JsonResponse
    {
        return response()->json($this->analytics->staff($this->context->id(), $this->days($request)));
    }
}
