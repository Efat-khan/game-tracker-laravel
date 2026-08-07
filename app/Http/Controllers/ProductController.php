<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Http\Resources\Present;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Services\AuditService;
use App\Support\Money;
use App\Support\Tenancy\CafeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProductController extends Controller
{
    public function __construct(
        private readonly CafeContext $context,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->context->scope(Product::class);

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return response()->json(
            $query->orderBy('category')->orderBy('name')->get()->map(Present::product(...))->all()
        );
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $product = Product::create([
            'cafe_id' => $this->context->id(),
            'name' => $request->string('name')->value(),
            'category' => $request->string('category', 'Snacks')->value(),
            'price' => Money::str($request->input('price')),
            'cost_price' => Money::str($request->input('cost_price', 0)),
            'is_active' => $request->boolean('is_active', true),
            'created_at' => now(),
        ]);

        $this->audit->log('product_create', 'product', $product->id, "Added product {$product->name}");

        return response()->json(Present::product($product), Response::HTTP_CREATED);
    }

    public function update(ProductRequest $request, int $id): JsonResponse
    {
        $product = $this->context->find(Product::class, $id);

        $product->fill($request->safe()->only(['name', 'category', 'is_active']));

        foreach (['price', 'cost_price'] as $field) {
            if ($request->has($field)) {
                $product->{$field} = Money::str($request->input($field));
            }
        }

        $product->save();

        $this->audit->log('product_update', 'product', $product->id, "Updated product {$product->name}");

        return response()->json(Present::product($product));
    }

    /** A product that has been sold is RETIRED, not deleted (§5.2). */
    public function destroy(int $id): Response
    {
        $product = $this->context->find(Product::class, $id);

        if (InvoiceItem::where('product_id', $product->id)->exists()) {
            $product->is_active = false;
            $product->save();

            $this->audit->log('product_delete', 'product', $product->id, "Retired product {$product->name} (has sales)");
        } else {
            $name = $product->name;
            $product->delete();

            $this->audit->log('product_delete', 'product', $id, "Deleted product {$name}");
        }

        return response()->noContent();
    }
}
