<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExpenseRequest;
use App\Models\Expense;
use App\Services\ExpenseService;
use App\Support\Money;
use App\Support\Tenancy\CafeContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExpenseController extends Controller
{
    public function __construct(
        private readonly CafeContext $context,
        private readonly ExpenseService $expenses,
    ) {}

    private function present(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'category' => $expense->category,
            'category_label' => $expense->categoryLabel(),
            'amount' => Money::str($expense->amount),
            'payment_method' => $expense->payment_method,
            'note' => $expense->note,
            'spent_on' => $expense->spent_on?->format('Y-m-d'),
            // Whether it came out of the drawer, and whether that drawer has
            // been counted — the screen greys out Delete once it has.
            'shift_id' => $expense->shift_id,
            'locked' => $expense->shift_id !== null && (bool) $expense->shift?->isClosed(),
            'actor_email' => $expense->actor_email,
            'created_at' => $expense->created_at?->utc()->format('Y-m-d\TH:i:s'),
        ];
    }

    /**
     * The ledger for a window, newest first, with the per-category totals the
     * screen shows above it.
     *
     * The category catalogue rides along so the form and the server cannot
     * drift apart on what a valid category is.
     */
    public function index(Request $request): JsonResponse
    {
        $from = $this->date($request, 'from', now()->startOfMonth()->toDateString());
        $to = $this->date($request, 'to', now()->toDateString());

        $query = $this->context->scope(Expense::class)
            ->with('shift')
            // Half-open on the upper bound: `spent_on` is stored with a zeroed
            // time, so `<= '2026-08-08'` would exclude that very day.
            ->where('spent_on', '>=', $from)
            ->where('spent_on', '<', CarbonImmutable::parse($to)->addDay());

        if ($request->filled('category')) {
            $query->where('category', $request->string('category')->value());
        }

        $expenses = $query->orderByDesc('spent_on')->orderByDesc('id')->limit(500)->get();

        $byCategory = $expenses
            ->groupBy('category')
            ->map(fn ($rows, $category) => [
                'category' => $category,
                'label' => Expense::CATEGORIES[$category] ?? $category,
                'total' => Money::str($rows->reduce(
                    fn ($carry, $row) => Money::of($carry)->plus(Money::of($row->amount)),
                    '0'
                )),
                'count' => $rows->count(),
            ])
            ->values()
            ->sortByDesc(fn ($row) => (float) $row['total'])
            ->values()
            ->all();

        return response()->json([
            'from' => $from,
            'to' => $to,
            'categories' => collect(Expense::CATEGORIES)
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])
                ->values()
                ->all(),
            'methods' => Expense::METHODS,
            'total' => Money::str($expenses->reduce(
                fn ($carry, $row) => Money::of($carry)->plus(Money::of($row->amount)),
                '0'
            )),
            'by_category' => $byCategory,
            'expenses' => $expenses->map($this->present(...))->all(),
        ]);
    }

    public function store(ExpenseRequest $request): JsonResponse
    {
        $expense = $this->expenses->record($request->validated());

        return response()->json($this->present($expense->load('shift')), Response::HTTP_CREATED);
    }

    /** Admin only. */
    public function destroy(int $id): Response
    {
        $expense = $this->context->find(Expense::class, $id);

        $this->expenses->remove($expense);

        return response()->noContent();
    }

    private function date(Request $request, string $key, string $fallback): string
    {
        $value = (string) $request->query($key, '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false
            ? $value
            : $fallback;
    }
}
