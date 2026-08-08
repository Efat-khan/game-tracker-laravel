<?php

namespace App\Http\Requests;

use App\Models\Expense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', Rule::in(array_keys(Expense::CATEGORIES))],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', 'string', Rule::in(Expense::METHODS)],
            'note' => ['sometimes', 'nullable', 'string', 'max:200'],
            /*
             * The day the cost belongs to. Optional — it defaults to today —
             * and never in the future, because a report that includes money
             * not yet spent is a report nobody can reconcile.
             *
             * A CASH expense is pinned to today regardless: the money comes
             * out of the drawer that is open right now, and yesterday's shift
             * has already been counted and signed off. The service enforces
             * that rather than this rule, so the message can explain itself.
             */
            'spent_on' => ['sometimes', 'date', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'spent_on.before_or_equal' => 'An expense cannot be dated in the future.',
        ];
    }
}
