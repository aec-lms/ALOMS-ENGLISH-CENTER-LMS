<?php

declare(strict_types=1);

namespace Plugins\Modules\CmsWebsite\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\CmsWebsite\Models\CmsPage;
use Plugins\Modules\CmsWebsite\Models\CmsPost;
use Plugins\Modules\CmsWebsite\Models\CmsSlider;

class CmsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $pages = Schema::hasTable('cms_pages') ? CmsPage::query()->latest()->get() : collect();
        $sliders = Schema::hasTable('cms_sliders')
            ? CmsSlider::query()->orderBy('key_name')->orderBy('sort_order')->get()
            : collect();
        $posts = Schema::hasTable('cms_posts') ? CmsPost::query()->latest()->get() : collect();

        $homepage = $this->loadHomepageConfig();

        return view('cms-website::index', compact('pages', 'sliders', 'posts', 'homepage'));
    }

    public function storePost(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['nullable', 'alpha_dash', 'max:180', 'unique:cms_posts,slug'],
            'category' => ['required', 'string', 'max:100'],
            'author' => ['nullable', 'string', 'max:120'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'content' => ['required', 'string'],
            'seo_title' => ['nullable', 'string', 'max:180'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'thumbnail' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'thumbnail_url' => ['nullable', 'string', 'max:255'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $slug = !empty($validated['slug'])
            ? strtolower($validated['slug'])
            : Str::slug($validated['title']) . '-' . Str::lower(Str::random(4));

        $thumbPath = $request->hasFile('thumbnail')
            ? $this->storeUploadedImage($request->file('thumbnail'), 'posts')
            : ($validated['thumbnail_url'] ?? null);

        $post = CmsPost::query()->create([
            'slug' => $slug,
            'title' => $validated['title'],
            'category' => $validated['category'],
            'author' => $validated['author'] ?: "Alom's English Center",
            'excerpt' => $validated['excerpt'] ?? Str::limit(strip_tags($validated['content']), 160),
            'content' => $validated['content'],
            'thumbnail_path' => $thumbPath,
            'seo_title' => $validated['seo_title'] ?? $validated['title'],
            'seo_description' => $validated['seo_description'] ?? null,
            'is_published' => $request->boolean('is_published', true),
        ]);

        $this->audit->log('cms.post_created', 'cms_post', (string) $post->id, ['slug' => $post->slug]);

        return back()->with('status', "Post '{$post->title}' created.");
    }

    public function updatePost(Request $request, CmsPost $post): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'alpha_dash', 'max:180', 'unique:cms_posts,slug,' . $post->id],
            'category' => ['required', 'string', 'max:100'],
            'author' => ['nullable', 'string', 'max:120'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'content' => ['required', 'string'],
            'seo_title' => ['nullable', 'string', 'max:180'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'thumbnail' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'thumbnail_url' => ['nullable', 'string', 'max:255'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $thumbPath = $post->thumbnail_path;
        if ($request->hasFile('thumbnail')) {
            $thumbPath = $this->storeUploadedImage($request->file('thumbnail'), 'posts');
        } elseif (!empty($validated['thumbnail_url'])) {
            $thumbPath = $validated['thumbnail_url'];
        }

        $post->update([
            'slug' => strtolower($validated['slug']),
            'title' => $validated['title'],
            'category' => $validated['category'],
            'author' => $validated['author'] ?: $post->author,
            'excerpt' => $validated['excerpt'] ?? $post->excerpt,
            'content' => $validated['content'],
            'thumbnail_path' => $thumbPath,
            'seo_title' => $validated['seo_title'] ?? $validated['title'],
            'seo_description' => $validated['seo_description'] ?? null,
            'is_published' => $request->boolean('is_published'),
        ]);

        $this->audit->log('cms.post_updated', 'cms_post', (string) $post->id, ['slug' => $post->slug]);

        return back()->with('status', "Post '{$post->title}' updated.");
    }

    public function destroyPost(CmsPost $post): RedirectResponse
    {
        $title = $post->title;
        $id = (string) $post->id;
        $post->delete();

        $this->audit->log('cms.post_deleted', 'cms_post', $id, ['title' => $title]);

        return back()->with('status', "Post '{$title}' deleted.");
    }

    public function storePage(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'slug' => ['required', 'alpha_dash', 'max:160', 'unique:cms_pages,slug'],
            'title' => ['required', 'string', 'max:180'],
            'seo_title' => ['nullable', 'string', 'max:180'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'content_body' => ['required', 'string'],
            'banner' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $bannerPath = $request->hasFile('banner')
            ? $this->storeUploadedImage($request->file('banner'), 'pages')
            : null;

        $page = CmsPage::query()->create([
            'slug' => strtolower($validated['slug']),
            'title' => $validated['title'],
            'banner_image' => $bannerPath,
            'blocks_json' => [['type' => 'rich_text', 'content' => $validated['content_body']]],
            'seo_title' => $validated['seo_title'] ?? $validated['title'],
            'seo_description' => $validated['seo_description'] ?? null,
            'is_published' => $request->boolean('is_published', true),
        ]);

        $this->audit->log('cms.page_created', 'cms_page', (string) $page->id, ['slug' => $page->slug]);

        return back()->with('status', "CMS page '/page/{$page->slug}' published.");
    }

    public function updatePage(Request $request, CmsPage $page): RedirectResponse
    {
        $validated = $request->validate([
            'slug' => ['required', 'alpha_dash', 'max:160', 'unique:cms_pages,slug,' . $page->id],
            'title' => ['required', 'string', 'max:180'],
            'seo_title' => ['nullable', 'string', 'max:180'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'content_body' => ['required', 'string'],
            'banner' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $bannerPath = $page->banner_image;
        if ($request->hasFile('banner')) {
            $bannerPath = $this->storeUploadedImage($request->file('banner'), 'pages');
        }

        $page->update([
            'slug' => strtolower($validated['slug']),
            'title' => $validated['title'],
            'banner_image' => $bannerPath,
            'blocks_json' => [['type' => 'rich_text', 'content' => $validated['content_body']]],
            'seo_title' => $validated['seo_title'] ?? $validated['title'],
            'seo_description' => $validated['seo_description'] ?? null,
            'is_published' => $request->boolean('is_published'),
        ]);

        $this->audit->log('cms.page_updated', 'cms_page', (string) $page->id, ['slug' => $page->slug]);

        return back()->with('status', "CMS page '/page/{$page->slug}' updated.");
    }

    public function destroyPage(CmsPage $page): RedirectResponse
    {
        $slug = $page->slug;
        $id = (string) $page->id;
        $page->delete();

        $this->audit->log('cms.page_deleted', 'cms_page', $id, ['slug' => $slug]);

        return back()->with('status', "CMS page '/page/{$slug}' deleted.");
    }

    public function storeSlider(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'key_name' => ['required', 'in:hero,testimonial_left,testimonial_right,featured_carousel'],
            'title' => ['required', 'string', 'max:180'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'cta_url' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'image_path' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $imagePath = $request->hasFile('image')
            ? $this->storeUploadedImage($request->file('image'), 'sliders')
            : ($validated['image_path'] ?? null);

        $slider = CmsSlider::query()->create([
            'key_name' => $validated['key_name'],
            'title' => $validated['title'],
            'subtitle' => $validated['subtitle'] ?? null,
            'image_path' => $imagePath,
            'cta_url' => $validated['cta_url'] ?? null,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->audit->log('cms.slider_created', 'cms_slider', (string) $slider->id, ['key' => $slider->key_name]);

        return back()->with('status', 'Slider/Carousel item added.');
    }

    public function updateSlider(Request $request, CmsSlider $slider): RedirectResponse
    {
        $validated = $request->validate([
            'key_name' => ['required', 'in:hero,testimonial_left,testimonial_right,featured_carousel'],
            'title' => ['required', 'string', 'max:180'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'cta_url' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'image_path' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $imagePath = $slider->image_path;
        if ($request->hasFile('image')) {
            $imagePath = $this->storeUploadedImage($request->file('image'), 'sliders');
        } elseif (!empty($validated['image_path'])) {
            $imagePath = $validated['image_path'];
        }

        $slider->update([
            'key_name' => $validated['key_name'],
            'title' => $validated['title'],
            'subtitle' => $validated['subtitle'] ?? null,
            'image_path' => $imagePath,
            'cta_url' => $validated['cta_url'] ?? null,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_active' => $request->boolean('is_active'),
        ]);

        $this->audit->log('cms.slider_updated', 'cms_slider', (string) $slider->id, ['key' => $slider->key_name]);

        return back()->with('status', 'Slider/Carousel item updated.');
    }

    public function destroySlider(CmsSlider $slider): RedirectResponse
    {
        $title = $slider->title;
        $id = (string) $slider->id;
        $slider->delete();

        $this->audit->log('cms.slider_deleted', 'cms_slider', $id, ['title' => $title]);

        return back()->with('status', "Slider '{$title}' deleted.");
    }

    public function saveHomepage(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'batches_title' => ['required', 'string', 'max:180'],
            'batches_subtitle' => ['required', 'string', 'max:180'],
            'stats_title' => ['required', 'string', 'max:180'],
            'stats_subtitle' => ['required', 'string', 'max:180'],
            'paths_title' => ['required', 'string', 'max:180'],
            'paths_subtitle' => ['required', 'string', 'max:320'],
            'reviews_title' => ['required', 'string', 'max:180'],
            'reviews_subtitle' => ['required', 'string', 'max:320'],
            'show_slider' => ['nullable', 'boolean'],
            'show_batches' => ['nullable', 'boolean'],
            'show_learning_paths' => ['nullable', 'boolean'],
            'show_reviews' => ['nullable', 'boolean'],
        ]);

        $payload = [
            'batches_title' => $validated['batches_title'],
            'batches_subtitle' => $validated['batches_subtitle'],
            'stats_title' => $validated['stats_title'],
            'stats_subtitle' => $validated['stats_subtitle'],
            'paths_title' => $validated['paths_title'],
            'paths_subtitle' => $validated['paths_subtitle'],
            'reviews_title' => $validated['reviews_title'],
            'reviews_subtitle' => $validated['reviews_subtitle'],
            'show_slider' => $request->boolean('show_slider'),
            'show_batches' => $request->boolean('show_batches'),
            'show_learning_paths' => $request->boolean('show_learning_paths'),
            'show_reviews' => $request->boolean('show_reviews'),
        ];

        SystemSetting::query()->updateOrCreate(
            ['key' => 'homepage_customizer'],
            [
                'value' => json_encode($payload, JSON_THROW_ON_ERROR),
                'is_encrypted' => false,
                'updated_by' => $request->user()?->id,
            ]
        );

        $this->audit->log('cms.homepage_customized', 'system_setting', 'homepage_customizer', []);

        return back()->with('status', 'Homepage customization saved.');
    }

    private function storeUploadedImage(UploadedFile $file, string $folder): string
    {
        $dir = public_path('uploads/' . $folder);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = Str::uuid()->toString() . '.' . $file->getClientOriginalExtension();
        $file->move($dir, $filename);

        return '/uploads/' . $folder . '/' . $filename;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadHomepageConfig(): array
    {
        $defaults = [
            'batches_title' => 'Online Batches are ongoing!',
            'batches_subtitle' => 'Book your seat now!',
            'stats_title' => 'See the stats!',
            'stats_subtitle' => 'The trust we are building!',
            'paths_title' => 'Choose Your Learning Path',
            'paths_subtitle' => 'Discover diverse courses across multiple disciplines designed to help you excel in your academic and admission journey.',
            'reviews_title' => 'What Our Students Say',
            'reviews_subtitle' => "Feedbacks from students who have transformed their learning journey with Alom's English Center.",
            'show_slider' => true,
            'show_batches' => true,
            'show_learning_paths' => true,
            'show_reviews' => true,
        ];

        if (!Schema::hasTable('system_settings')) {
            return $defaults;
        }

        $raw = SystemSetting::query()->where('key', 'homepage_customizer')->value('value');
        if (!is_string($raw) || $raw === '') {
            return $defaults;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_merge($defaults, $decoded) : $defaults;
    }
}
