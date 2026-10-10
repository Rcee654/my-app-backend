<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ProductController extends Controller
{
    public function categories(): JsonResponse
    {
        return response()->json(['categories' => Category::where('is_active', true)->orderBy('name')->get()]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Product::with('category')
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->latest();

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhereHas('category', fn ($category) => $category->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        return response()->json(['products' => $query->get()]);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->ensureOwner($request, $product);
        return response()->json(['product' => $product->load('category', 'movements')]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateProduct($request);
        $validated['user_id'] = $request->user()->id;
        $validated['image'] = $this->storeImage($request);

        $product = DB::transaction(function () use ($validated, $request): Product {
            $product = Product::create($validated);
            if ($product->stock_qty > 0) {
                InventoryMovement::create([
                    'product_id' => $product->id,
                    'user_id' => $request->user()->id,
                    'type' => 'add',
                    'quantity' => $product->stock_qty,
                    'previous_stock' => 0,
                    'new_stock' => $product->stock_qty,
                    'reason' => 'Initial stock',
                    'reference_type' => 'manual',
                ]);
            }
            return $product;
        });

        return response()->json(['message' => 'Product created.', 'product' => $product->load('category')], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->ensureOwner($request, $product);
        $validated = $this->validateProduct($request, $product);
        unset($validated['stock_qty']);
        if ($request->hasFile('image')) {
            if ($product->image) Storage::disk('public')->delete($product->image);
            $validated['image'] = $this->storeImage($request);
        }
        $product->update($validated);
        return response()->json(['message' => 'Product updated.', 'product' => $product->fresh()->load('category')]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->ensureOwner($request, $product);
        $product->update(['is_active' => false]);
        $product->delete();
        return response()->json(['message' => 'Product deactivated.']);
    }

    public function availability(Request $request, Product $product): JsonResponse
    {
        $this->ensureOwner($request, $product);
        $validated = $request->validate(['is_available' => ['required', 'boolean']]);
        $product->update($validated);
        return response()->json(['product' => $product->fresh()->load('category')]);
    }

    public function inventory(Request $request): JsonResponse
    {
        $products = Product::with('category')
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->latest()
            ->get();
        return response()->json([
            'products' => $products,
            'summary' => [
                'in_stock' => $products->filter(fn (Product $p) => $p->stock_qty > $p->low_stock_level)->count(),
                'low' => $products->filter(fn (Product $p) => $p->stock_qty > 0 && $p->stock_qty <= $p->low_stock_level)->count(),
                'out' => $products->where('stock_qty', 0)->count(),
            ],
        ]);
    }

    public function updateStock(Request $request, Product $product): JsonResponse
    {
        $this->ensureOwner($request, $product);
        $validated = $request->validate([
            'action' => ['required', Rule::in(['add', 'remove'])],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $product = DB::transaction(function () use ($product, $validated, $request): Product {
            $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->stock_qty;
            if ($validated['action'] === 'remove' && $validated['quantity'] > $previous) {
                abort(422, "Cannot remove {$validated['quantity']} items. Only {$previous} items are currently in stock.");
            }
            $newStock = $validated['action'] === 'add'
                ? $previous + $validated['quantity']
                : $previous - $validated['quantity'];
            $locked->update(['stock_qty' => $newStock]);
            InventoryMovement::create([
                'product_id' => $locked->id,
                'user_id' => $request->user()->id,
                'type' => $validated['action'],
                'quantity' => $validated['quantity'],
                'previous_stock' => $previous,
                'new_stock' => $newStock,
                'reason' => $validated['reason'] ?? null,
                'reference_type' => 'manual',
            ]);
            return $locked->fresh()->load('category');
        });

        return response()->json(['message' => 'Stock updated.', 'product' => $product]);
    }

    private function validateProduct(Request $request, ?Product $product = null): array
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'exists:categories,id'],
            'price' => ['required', 'numeric', 'min:0'],
            'stock_qty' => ['sometimes', 'integer', 'min:0'],
            'low_stock_level' => ['required', 'integer', 'min:0'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_available' => ['sometimes', 'boolean'],
            'image' => ['nullable', 'file', 'max:5120'],
        ]);

        if ($validator->fails()) {
            $image = $request->file('image');
            Log::warning('Product validation failed.', [
                'user_id' => $request->user()?->id,
                'product_id' => $product?->id,
                'fields' => array_keys($validator->errors()->toArray()),
                'has_image' => $request->hasFile('image'),
                'image_error' => $image?->getError(),
                'image_valid' => $image?->isValid(),
                'image_client_mime' => $image?->getClientMimeType(),
            ]);
            abort(response()->json([
                'message' => 'Please correct the highlighted product fields.',
                'errors' => $validator->errors(),
            ], 422));
        }

        return $validator->validated();
    }

    private function storeImage(Request $request): ?string
    {
        return $request->hasFile('image') ? $request->file('image')->store('products', 'public') : null;
    }

    private function ensureOwner(Request $request, Product $product): void
    {
        abort_unless($product->user_id === $request->user()->id, 403, 'You do not have access to this product.');
        abort_unless($request->user()->role === 'admin', 403, 'Only admins can manage products and inventory.');
    }
}
