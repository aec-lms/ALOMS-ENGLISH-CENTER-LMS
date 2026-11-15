@extends('layouts.admin')
@section('title', 'CMS, Posts, Pages, Sliders & Homepage — AEC LMS')
@section('content')
<div class="space-y-6 max-w-6xl">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">Website CMS, Page, Post, Slider &amp; Homepage Builder</h1>
            <p class="text-xs text-slate-500 mt-0.5">Create, edit, update, upload images, and delete blog posts, custom pages, sliders, and homepage sections.</p>
        </div>
        <a href="{{ route('home') }}" target="_blank" class="px-4 py-2 rounded-lg bg-white border border-slate-300 text-xs font-bold text-slate-700 hover:bg-slate-50">
            Preview Live Site &rarr;
        </a>
    </div>

    <!-- Tab Bar -->
    <div class="flex flex-wrap gap-2 border-b border-slate-200 pb-3" id="cms-admin-tabs">
        <button type="button" data-tab="posts" class="cms-tab-btn px-4 py-2 rounded-lg text-xs font-bold bg-[#0284C7] text-white">Blog Posts ({{ $posts->count() }})</button>
        <button type="button" data-tab="pages" class="cms-tab-btn px-4 py-2 rounded-lg text-xs font-bold bg-white border border-slate-200 text-slate-700">Custom Pages ({{ $pages->count() }})</button>
        <button type="button" data-tab="sliders" class="cms-tab-btn px-4 py-2 rounded-lg text-xs font-bold bg-white border border-slate-200 text-slate-700">Sliders &amp; Carousels ({{ $sliders->count() }})</button>
        <button type="button" data-tab="homepage" class="cms-tab-btn px-4 py-2 rounded-lg text-xs font-bold bg-white border border-slate-200 text-slate-700">Homepage Customizer</button>
    </div>

    <!-- TAB 1: BLOG POSTS (Create / Edit / Delete + Thumbnail Upload) -->
    <section id="tab-posts" class="cms-tab-panel space-y-6">
        <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
            <h2 class="text-sm font-bold text-slate-900">Create New Blog Post / Article</h2>
            <form method="POST" action="{{ route('plugins.cms.posts.store') }}" enctype="multipart/form-data" class="grid sm:grid-cols-2 gap-3 text-xs">
                @csrf
                <input name="title" required placeholder="Post Title *" class="rounded-lg border border-slate-300 px-3 py-2">
                <input name="slug" placeholder="Custom URL Slug (optional)" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
                <input name="category" required value="IELTS Preparation" placeholder="Category *" class="rounded-lg border border-slate-300 px-3 py-2">
                <input name="author" value="Alom's English Center" placeholder="Author Name" class="rounded-lg border border-slate-300 px-3 py-2">
                <div>
                    <label class="block font-semibold text-slate-600 mb-1">Upload Thumbnail Picture (JPG/PNG/WebP)</label>
                    <input type="file" name="thumbnail" accept="image/*" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 bg-slate-50">
                </div>
                <div>
                    <label class="block font-semibold text-slate-600 mb-1">Or Image URL</label>
                    <input name="thumbnail_url" placeholder="/assets/images/..." class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>
                <input name="excerpt" placeholder="Short Summary / Excerpt" class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2">
                <textarea name="content" rows="4" required placeholder="Full Post Content *" class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2"></textarea>
                <div class="sm:col-span-2 flex items-center justify-between">
                    <label class="inline-flex items-center gap-2 font-semibold text-slate-700">
                        <input type="checkbox" name="is_published" value="1" checked> Publish immediately
                    </label>
                    <button type="submit" class="px-5 py-2 rounded-lg bg-[#0284C7] hover:bg-[#0369A1] text-white font-bold">Publish Post</button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
            <h2 class="text-sm font-bold text-slate-900">All Posts</h2>
            <div class="space-y-4">
                @forelse ($posts as $post)
                    <div class="border border-slate-200 rounded-xl p-4 space-y-3 text-xs">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                @if ($post->thumbnail_path)
                                    <img src="{{ $post->thumbnail_path }}" alt="{{ $post->title }}" class="w-16 h-12 rounded-lg object-cover border">
                                @endif
                                <div>
                                    <div class="font-bold text-sm text-slate-900">{{ $post->title }}</div>
                                    <div class="text-slate-500 font-mono">/learn#{{ $post->slug }} &middot; {{ $post->category }} &middot; {{ $post->is_published ? 'Published' : 'Draft' }}</div>
                                </div>
                            </div>
                            <form method="POST" action="{{ route('plugins.cms.posts.destroy', $post) }}" onsubmit="return confirm('Delete this post?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-700 font-bold hover:bg-rose-50">Delete</button>
                            </form>
                        </div>

                        <details class="pt-2 border-t border-slate-100">
                            <summary class="cursor-pointer font-semibold text-[#0284C7]">Edit / Modify Post &amp; Thumbnail</summary>
                            <form method="POST" action="{{ route('plugins.cms.posts.update', $post) }}" enctype="multipart/form-data" class="mt-3 grid sm:grid-cols-2 gap-3">
                                @csrf
                                @method('PUT')
                                <input name="title" required value="{{ $post->title }}" class="rounded-lg border border-slate-300 px-3 py-2">
                                <input name="slug" required value="{{ $post->slug }}" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
                                <input name="category" required value="{{ $post->category }}" class="rounded-lg border border-slate-300 px-3 py-2">
                                <input name="author" value="{{ $post->author }}" class="rounded-lg border border-slate-300 px-3 py-2">
                                <input type="file" name="thumbnail" accept="image/*" class="rounded-lg border border-slate-300 px-2 py-1.5 bg-slate-50">
                                <input name="thumbnail_url" value="{{ $post->thumbnail_path }}" placeholder="Image Path / URL" class="rounded-lg border border-slate-300 px-3 py-2">
                                <input name="excerpt" value="{{ $post->excerpt }}" class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2">
                                <textarea name="content" rows="3" required class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2">{{ $post->content }}</textarea>
                                <div class="sm:col-span-2 flex items-center justify-between">
                                    <label class="inline-flex items-center gap-2 font-semibold">
                                        <input type="checkbox" name="is_published" value="1" @checked($post->is_published)> Published
                                    </label>
                                    <button type="submit" class="px-4 py-1.5 rounded-lg bg-slate-900 text-white font-bold">Update Post</button>
                                </div>
                            </form>
                        </details>
                    </div>
                @empty
                    <p class="text-xs text-slate-400">No blog posts created yet.</p>
                @endforelse
            </div>
        </div>
    </section>

    <!-- TAB 2: CUSTOM PAGES (Create / Edit / Delete + Banner Upload) -->
    <section id="tab-pages" class="cms-tab-panel hidden space-y-6">
        <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
            <h2 class="text-sm font-bold text-slate-900">Create Custom Page</h2>
            <form method="POST" action="{{ route('plugins.cms.pages.store') }}" enctype="multipart/form-data" class="grid sm:grid-cols-2 gap-3 text-xs">
                @csrf
                <input name="title" required placeholder="Page Title (e.g. Scholarship Policy) *" class="rounded-lg border border-slate-300 px-3 py-2">
                <input name="slug" required placeholder="Slug (e.g. scholarship-policy) *" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
                <input name="seo_title" placeholder="SEO Title" class="rounded-lg border border-slate-300 px-3 py-2">
                <div>
                    <label class="block font-semibold text-slate-600 mb-1">Upload Banner Picture</label>
                    <input type="file" name="banner" accept="image/*" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 bg-slate-50">
                </div>
                <textarea name="seo_description" rows="2" placeholder="SEO Meta Description" class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2"></textarea>
                <textarea name="content_body" rows="5" required placeholder="Page Content Body *" class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2"></textarea>
                <div class="sm:col-span-2 flex items-center justify-between">
                    <label class="inline-flex items-center gap-2 font-semibold">
                        <input type="checkbox" name="is_published" value="1" checked> Publish immediately
                    </label>
                    <button type="submit" class="px-5 py-2 rounded-lg bg-[#0284C7] hover:bg-[#0369A1] text-white font-bold">Create Custom Page</button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
            <h2 class="text-sm font-bold text-slate-900">Custom Pages</h2>
            <div class="space-y-4">
                @forelse ($pages as $page)
                    @php
                        $body = is_array($page->blocks_json) ? ($page->blocks_json[0]['content'] ?? '') : '';
                    @endphp
                    <div class="border border-slate-200 rounded-xl p-4 space-y-3 text-xs">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div>
                                <div class="font-bold text-sm text-slate-900">{{ $page->title }}</div>
                                <div class="text-slate-500 font-mono">
                                    <a href="{{ route('public.custom_page', $page->slug) }}" target="_blank" class="text-[#0284C7] hover:underline">/page/{{ $page->slug }}</a>
                                    &middot; {{ $page->is_published ? 'Published' : 'Draft' }}
                                </div>
                            </div>
                            <form method="POST" action="{{ route('plugins.cms.pages.destroy', $page) }}" onsubmit="return confirm('Delete this custom page?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-700 font-bold hover:bg-rose-50">Delete</button>
                            </form>
                        </div>

                        <details class="pt-2 border-t border-slate-100">
                            <summary class="cursor-pointer font-semibold text-[#0284C7]">Edit / Update Custom Page</summary>
                            <form method="POST" action="{{ route('plugins.cms.pages.update', $page) }}" enctype="multipart/form-data" class="mt-3 grid sm:grid-cols-2 gap-3">
                                @csrf
                                @method('PUT')
                                <input name="title" required value="{{ $page->title }}" class="rounded-lg border border-slate-300 px-3 py-2">
                                <input name="slug" required value="{{ $page->slug }}" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
                                <input name="seo_title" value="{{ $page->seo_title }}" class="rounded-lg border border-slate-300 px-3 py-2">
                                <input type="file" name="banner" accept="image/*" class="rounded-lg border border-slate-300 px-2 py-1.5 bg-slate-50">
                                <textarea name="seo_description" rows="2" class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2">{{ $page->seo_description }}</textarea>
                                <textarea name="content_body" rows="4" required class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2">{{ $body }}</textarea>
                                <div class="sm:col-span-2 flex items-center justify-between">
                                    <label class="inline-flex items-center gap-2 font-semibold">
                                        <input type="checkbox" name="is_published" value="1" @checked($page->is_published)> Published
                                    </label>
                                    <button type="submit" class="px-4 py-1.5 rounded-lg bg-slate-900 text-white font-bold">Update Page</button>
                                </div>
                            </form>
                        </details>
                    </div>
                @empty
                    <p class="text-xs text-slate-400">No custom pages created yet.</p>
                @endforelse
            </div>
        </div>
    </section>

    <!-- TAB 3: SLIDERS & CAROUSELS (Upload / Edit / Modify / Delete) -->
    <section id="tab-sliders" class="cms-tab-panel hidden space-y-6">
        <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
            <h2 class="text-sm font-bold text-slate-900">Add &amp; Upload New Slider / Carousel Item</h2>
            <form method="POST" action="{{ route('plugins.cms.sliders.store') }}" enctype="multipart/form-data" class="grid sm:grid-cols-2 gap-3 text-xs">
                @csrf
                <select name="key_name" required class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                    <option value="hero">Hero Main Carousel</option>
                    <option value="testimonial_left">Student Review Left Carousel</option>
                    <option value="testimonial_right">Student Review Right Carousel</option>
                    <option value="featured_carousel">Featured Banner Carousel</option>
                </select>
                <input name="sort_order" type="number" min="0" value="0" placeholder="Sort Order" class="rounded-lg border border-slate-300 px-3 py-2">
                <input name="title" required placeholder="Slide Title / Student Name *" class="rounded-lg border border-slate-300 px-3 py-2">
                <input name="subtitle" placeholder="Subtitle / Review Quote" class="rounded-lg border border-slate-300 px-3 py-2">
                <div>
                    <label class="block font-semibold text-slate-600 mb-1">Upload Slider Picture</label>
                    <input type="file" name="image" accept="image/*" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 bg-slate-50">
                </div>
                <div>
                    <label class="block font-semibold text-slate-600 mb-1">Or Image Path &amp; Link URL</label>
                    <div class="grid grid-cols-2 gap-2">
                        <input name="image_path" placeholder="/assets/images/..." class="rounded-lg border border-slate-300 px-3 py-2">
                        <input name="cta_url" placeholder="/courses" class="rounded-lg border border-slate-300 px-3 py-2">
                    </div>
                </div>
                <div class="sm:col-span-2 flex items-center justify-between">
                    <label class="inline-flex items-center gap-2 font-semibold">
                        <input type="checkbox" name="is_active" value="1" checked> Active on homepage
                    </label>
                    <button type="submit" class="px-5 py-2 rounded-lg bg-[#0284C7] hover:bg-[#0369A1] text-white font-bold">Upload &amp; Add Slide</button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
            <h2 class="text-sm font-bold text-slate-900">Manage Sliders &amp; Carousels</h2>
            <div class="space-y-4">
                @forelse ($sliders as $slide)
                    <div class="border border-slate-200 rounded-xl p-4 space-y-3 text-xs">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                @if ($slide->image_path)
                                    <img src="{{ $slide->image_path }}" alt="{{ $slide->title }}" class="w-20 h-12 rounded-lg object-cover border">
                                @endif
                                <div>
                                    <div class="font-bold text-sm text-slate-900">{{ $slide->title }}</div>
                                    <div class="text-slate-500 font-mono">{{ $slide->key_name }} &middot; Order {{ $slide->sort_order }} &middot; {{ $slide->is_active ? 'Active' : 'Hidden' }}</div>
                                </div>
                            </div>
                            <form method="POST" action="{{ route('plugins.cms.sliders.destroy', $slide) }}" onsubmit="return confirm('Delete this slider?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-700 font-bold hover:bg-rose-50">Delete</button>
                            </form>
                        </div>

                        <details class="pt-2 border-t border-slate-100">
                            <summary class="cursor-pointer font-semibold text-[#0284C7]">Edit / Replace Slider Image</summary>
                            <form method="POST" action="{{ route('plugins.cms.sliders.update', $slide) }}" enctype="multipart/form-data" class="mt-3 grid sm:grid-cols-2 gap-3">
                                @csrf
                                @method('PUT')
                                <select name="key_name" required class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                                    @foreach (['hero' => 'Hero Main Carousel', 'testimonial_left' => 'Review Left Carousel', 'testimonial_right' => 'Review Right Carousel', 'featured_carousel' => 'Featured Carousel'] as $k => $lbl)
                                        <option value="{{ $k }}" @selected($slide->key_name === $k)>{{ $lbl }}</option>
                                    @endforeach
                                </select>
                                <input name="sort_order" type="number" min="0" value="{{ $slide->sort_order }}" class="rounded-lg border border-slate-300 px-3 py-2">
                                <input name="title" required value="{{ $slide->title }}" class="rounded-lg border border-slate-300 px-3 py-2">
                                <input name="subtitle" value="{{ $slide->subtitle }}" class="rounded-lg border border-slate-300 px-3 py-2">
                                <input type="file" name="image" accept="image/*" class="rounded-lg border border-slate-300 px-2 py-1.5 bg-slate-50">
                                <div class="grid grid-cols-2 gap-2">
                                    <input name="image_path" value="{{ $slide->image_path }}" placeholder="Image Path" class="rounded-lg border border-slate-300 px-3 py-2">
                                    <input name="cta_url" value="{{ $slide->cta_url }}" placeholder="CTA URL" class="rounded-lg border border-slate-300 px-3 py-2">
                                </div>
                                <div class="sm:col-span-2 flex items-center justify-between">
                                    <label class="inline-flex items-center gap-2 font-semibold">
                                        <input type="checkbox" name="is_active" value="1" @checked($slide->is_active)> Active
                                    </label>
                                    <button type="submit" class="px-4 py-1.5 rounded-lg bg-slate-900 text-white font-bold">Update Slide</button>
                                </div>
                            </form>
                        </details>
                    </div>
                @empty
                    <p class="text-xs text-slate-400">No slider entries created yet.</p>
                @endforelse
            </div>
        </div>
    </section>

    <!-- TAB 4: HOMEPAGE CUSTOMIZER -->
    <section id="tab-homepage" class="cms-tab-panel hidden">
        <form method="POST" action="{{ route('plugins.cms.homepage.save') }}" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-5 text-xs">
            @csrf
            <h2 class="text-sm font-bold text-slate-900">Customize Homepage Sections &amp; Headlines</h2>

            <div class="grid sm:grid-cols-2 gap-4">
                <label class="block font-semibold text-slate-700">Batches Card Heading
                    <input name="batches_title" required value="{{ $homepage['batches_title'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">
                </label>
                <label class="block font-semibold text-slate-700">Batches Card Subheading
                    <input name="batches_subtitle" required value="{{ $homepage['batches_subtitle'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">
                </label>
                <label class="block font-semibold text-slate-700">Stats Card Heading
                    <input name="stats_title" required value="{{ $homepage['stats_title'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">
                </label>
                <label class="block font-semibold text-slate-700">Stats Card Subheading
                    <input name="stats_subtitle" required value="{{ $homepage['stats_subtitle'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">
                </label>
                <label class="block font-semibold text-slate-700">Learning Path Section Title
                    <input name="paths_title" required value="{{ $homepage['paths_title'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">
                </label>
                <label class="block font-semibold text-slate-700">Reviews Section Title
                    <input name="reviews_title" required value="{{ $homepage['reviews_title'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">
                </label>
                <label class="sm:col-span-2 block font-semibold text-slate-700">Learning Path Subtitle
                    <input name="paths_subtitle" required value="{{ $homepage['paths_subtitle'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">
                </label>
                <label class="sm:col-span-2 block font-semibold text-slate-700">Reviews Section Subtitle
                    <input name="reviews_subtitle" required value="{{ $homepage['reviews_subtitle'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">
                </label>
            </div>

            <div class="pt-3 border-t border-slate-100 grid sm:grid-cols-2 lg:grid-cols-4 gap-3 font-semibold text-slate-700">
                <label class="inline-flex items-center gap-2"><input type="checkbox" name="show_slider" value="1" @checked($homepage['show_slider'])> Show Hero Slider</label>
                <label class="inline-flex items-center gap-2"><input type="checkbox" name="show_batches" value="1" @checked($homepage['show_batches'])> Show Dual Bento Cards</label>
                <label class="inline-flex items-center gap-2"><input type="checkbox" name="show_learning_paths" value="1" @checked($homepage['show_learning_paths'])> Show Learning Paths</label>
                <label class="inline-flex items-center gap-2"><input type="checkbox" name="show_reviews" value="1" @checked($homepage['show_reviews'])> Show Student Reviews</label>
            </div>

            <div class="text-right">
                <button type="submit" class="px-6 py-2.5 rounded-lg bg-[#0284C7] hover:bg-[#0369A1] text-white font-bold">Save Homepage Customization</button>
            </div>
        </form>
    </section>
</div>

<script>
(() => {
    const buttons = document.querySelectorAll('.cms-tab-btn');
    const panels = document.querySelectorAll('.cms-tab-panel');
    buttons.forEach(btn => {
        btn.addEventListener('click', () => {
            const target = btn.getAttribute('data-tab');
            buttons.forEach(b => {
                b.className = 'cms-tab-btn px-4 py-2 rounded-lg text-xs font-bold bg-white border border-slate-200 text-slate-700';
            });
            btn.className = 'cms-tab-btn px-4 py-2 rounded-lg text-xs font-bold bg-[#0284C7] text-white';
            panels.forEach(p => {
                p.classList.toggle('hidden', p.id !== 'tab-' + target);
            });
        });
    });
})();
</script>
@endsection
