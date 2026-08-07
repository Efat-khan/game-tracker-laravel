<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdjustRequest;
use App\Http\Requests\TopupRequest;
use App\Http\Resources\Present;
use App\Models\Customer;
use App\Models\GameSession;
use App\Models\Package;
use App\Models\WalletTransaction;
use App\Services\AuditService;
use App\Services\LoyaltyService;
use App\Services\ShiftService;
use App\Services\WalletService;
use App\Support\Money;
use App\Support\Tenancy\CafeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(
        private readonly CafeContext $context,
        private readonly LoyaltyService $loyalty,
        private readonly WalletService $wallet,
        private readonly ShiftService $shifts,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->context->scope(Customer::class);

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->value().'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('phone_or_id', 'like', $term));
        }

        $limit = min(1000, max(1, (int) $request->query('limit', 200)));

        $customers = $query->orderByDesc('id')->limit($limit)->get();

        $visits = GameSession::whereIn('customer_id', $customers->pluck('id'))
            ->where('status', 'completed')
            ->selectRaw('customer_id, COUNT(*) as c')
            ->groupBy('customer_id')
            ->pluck('c', 'customer_id');

        return response()->json($customers->map(function (Customer $customer) use ($visits) {
            $spend = $this->loyalty->lifetimeSpend($customer);

            return Present::customer(
                $customer,
                Money::str($spend),
                (int) ($visits[$customer->id] ?? 0),
                $this->loyalty->tierFor($customer, $spend),
            );
        })->all());
    }

    public function show(int $id): JsonResponse
    {
        $customer = $this->context->find(Customer::class, $id);
        $spend = $this->loyalty->lifetimeSpend($customer);

        $visits = GameSession::where('customer_id', $customer->id)->where('status', 'completed')->count();

        return response()->json(Present::customer(
            $customer,
            Money::str($spend),
            $visits,
            $this->loyalty->tierFor($customer, $spend),
        ));
    }

    /** The ledger — the audit trail behind the balance. */
    public function wallet(Request $request, int $id): JsonResponse
    {
        $customer = $this->context->find(Customer::class, $id);

        $limit = min(500, max(1, (int) $request->query('limit', 100)));

        $transactions = WalletTransaction::where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'balance' => Money::str($customer->balance),
            'transactions' => $transactions->map(Present::walletTransaction(...))->all(),
        ]);
    }

    /**
     * Add credit. A package grants its `credit`, which is deliberately more
     * than its `price` — the bonus is what drives the upfront cash (§5.3).
     */
    public function topup(TopupRequest $request, int $id): JsonResponse
    {
        $customer = $this->context->find(Customer::class, $id);

        $method = $request->string('payment_method', 'cash')->value();
        $note = $request->string('note', '')->value();
        $package = null;

        if ($request->filled('package_id')) {
            $package = $this->context->find(Package::class, (int) $request->input('package_id'));
            $credit = Money::of($package->credit);
            $note = $note !== '' ? $note : $package->name;
        } else {
            $credit = Money::of($request->input('amount'));
        }

        $transaction = $this->wallet->record($customer, 'topup', $credit, [
            'package_id' => $package?->id,
            'note' => mb_substr($note, 0, 200),
            'payment_method' => $method,
            // Top-ups land in the shift that took the money, same as sales.
            'shift_id' => $this->shifts->current($customer->cafe_id)?->id,
        ]);

        $this->audit->log('wallet_topup', 'customer', $customer->id, sprintf(
            'Topped up %s by %s (%s)%s',
            $customer->name,
            Money::str($credit),
            $method,
            $package !== null ? " — {$package->name}" : '',
        ));

        return response()->json([
            'transaction' => Present::walletTransaction($transaction),
            'balance' => Money::str($customer->fresh()->balance),
        ]);
    }

    /** Admin only — a manual correction, signed, always explained. */
    public function adjust(AdjustRequest $request, int $id): JsonResponse
    {
        $customer = $this->context->find(Customer::class, $id);

        $amount = Money::of($request->input('amount'));
        $reason = $request->string('reason')->value();

        $transaction = $this->wallet->record($customer, 'adjust', $amount, [
            'note' => mb_substr($reason, 0, 200),
        ]);

        $this->audit->log('wallet_adjust', 'customer', $customer->id, sprintf(
            'Adjusted %s by %s — %s',
            $customer->name,
            Money::str($amount),
            $reason,
        ));

        return response()->json([
            'transaction' => Present::walletTransaction($transaction),
            'balance' => Money::str($customer->fresh()->balance),
        ]);
    }
}
