<?php

declare(strict_types=1);

namespace Plugins\Modules\StorefrontCatalog\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\StorefrontCatalog\Models\StorefrontItem;

class StorefrontController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $items = Schema::hasTable('storefront_items')
            ? StorefrontItem::query()->orderBy('type')->latest('id')->get()
            : collect();

        return view('storefront-catalog::index', compact('items'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:product,ebook'],
            'title' => ['required', 'string', 'max:200'],
            'category' => ['required', 'string', 'max:100'],
            'badge' => ['nullable', 'string', 'max:60'],
            'price' => ['required', 'numeric', 'min:0'],
            'original_price' => ['nullable', 'numeric', 'min:0'],
            'pages' => ['nullable', 'integer', 'min:1'],
            'stock_count' => ['nullable', 'integer', 'min:0'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'thumbnail' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'thumbnail_url' => ['nullable', 'string', 'max:255'],
            'download_url' => ['nullable', 'string', 'max:255'],
            'is_free' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $thumb = $request->hasFile('thumbnail')
            ? $this->storeThumbnail($request->file('thumbnail'))
            : ($validated['thumbnail_url'] ?? null);

        $isFree = $request->boolean('is_free') || (float) $validated['price'] <= 0;

        $item = StorefrontItem::query()->create([
            'type' => $validated['type'],
            'slug' => Str::slug($validated['title']) . '-' . Str::lower(Str::random(4)),
            'title' => $validated['title'],
            'category' => $validated['category'],
            'badge' => $validated['badge'] ?: ($validated['type'] === 'ebook' ? ($isFree ? 'Free' : 'Premium') : 'Featured'),
            'price' => $isFree ? 0 : (float) $validated['price'],
            'original_price' => (float) ($validated['original_price'] ?? $validated['price']),
            'is_free' => $isFree,
            'pages' => (int) ($validated['pages'] ?? 64),
            'stock_count' => (int) ($validated['stock_count'] ?? 50),
            'summary' => $validated['summary'] ?? null,
            'thumbnail_path' => $thumb,
            'download_url' => $validated['download_url'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->audit->log('storefront.created', 'storefront_item', (string) $item->id, ['type' => $item->type, 'title' => $item->title]);

        return back()->with('status', ucfirst($item->type) . " '{$item->title}' created.");
    }

    public function update(Request $request, StorefrontItem $item): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:product,ebook'],
            'title' => ['required', 'string', 'max:200'],
            'category' => ['required', 'string', 'max:100'],
            'badge' => ['nullable', 'string', 'max:60'],
            'price' => ['required', 'numeric', 'min:0'],
            'original_price' => ['nullable', 'numeric', 'min:0'],
            'pages' => ['nullable', 'integer', 'min:1'],
            'stock_count' => ['nullable', 'integer', 'min:0'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'thumbnail' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'thumbnail_url' => ['nullable', 'string', 'max:255'],
            'download_url' => ['nullable', 'string', 'max:255'],
            'is_free' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $thumb = $item->thumbnail_path;
        if ($request->hasFile('thumbnail')) {
            $thumb = $this->storeThumbnail($request->file('thumbnail'));
        } elseif (!empty($validated['thumbnail_url'])) {
            $thumb = $validated['thumbnail_url'];
        }

        $isFree = $request->boolean('is_free') || (float) $validated['price'] <= 0;

        $item->update([
            'type' => $validated['type'],
            'title' => $validated['title'],
            'category' => $validated['category'],
            'badge' => $validated['badge'] ?? $item->badge,
            'price' => $isFree ? 0 : (float) $validated['price'],
            'original_price' => (float) ($validated['original_price'] ?? $validated['price']),
            'is_free' => $isFree,
            'pages' => (int) ($validated['pages'] ?? $item->pages),
            'stock_count' => (int) ($validated['stock_count'] ?? $item->stock_count),
            'summary' => $validated['summary'] ?? $item->summary,
            'thumbnail_path' => $thumb,
            'download_url' => $validated['download_url'] ?? $item->download_url,
            'is_active' => $request->boolean('is_active'),
        ]);

        $this->audit->log('storefront.updated', 'storefront_item', (string) $item->id, ['title' => $item->title]);

        return back()->with('status', "'{$item->title}' and its thumbnail updated.");
    }

    public function destroy(StorefrontItem $item): RedirectResponse
    {
        $title = $item->title;
        $id = (string) $item->id;
        $item->delete();

        $this->audit->log('storefront.deleted', 'storefront_item', $id, ['title' => $title]);

        return back()->with('status', "'{$title}' removed.");
    }

    private function storeThumbnail(UploadedFile $file): string
    {
        $dir = public_path('uploads/storefront');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = Str::uuid()->toString() . '.' . $file->getClientOriginalExtension();
        $file->move($dir, $filename);

        return '/uploads/storefront/' . $filename;
    }
}
