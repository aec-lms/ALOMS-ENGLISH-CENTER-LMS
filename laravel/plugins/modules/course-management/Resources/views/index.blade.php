@extends('layouts.admin')
@section('title', 'Courses, Categories & Live Classes — AEC LMS')
@section('content')
<div class="space-y-6 max-w-6xl">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">Courses, Categories &amp; Live Classes</h1>
            <p class="text-xs text-slate-500 mt-0.5">Create, edit, update, upload thumbnails, organize categories, and manage live virtual classes.</p>
        </div>
        <a href="{{ route('public.courses') }}" target="_blank" class="px-4 py-2 rounded-lg bg-white border border-slate-300 text-xs font-bold text-slate-700 hover:bg-slate-50">
            View Public Courses &rarr;
        </a>
    </div>

    <!-- Tab Bar -->
    <div class="flex flex-wrap gap-2 border-b border-slate-200 pb-3">
        <button type="button" data-tab="courses" class="course-tab-btn px-4 py-2 rounded-lg text-xs font-bold bg-[#0284C7] text-white">Courses ({{ $courses->count() }})</button>
        <button type="button" data-tab="categories" class="course-tab-btn px-4 py-2 rounded-lg text-xs font-bold bg-white border border-slate-200 text-slate-700">Categories ({{ $categories->count() }})</button>
        <button type="button" data-tab="live" class="course-tab-btn px-4 py-2 rounded-lg text-xs font-bold bg-white border border-slate-200 text-slate-700">Live Classes ({{ $liveClasses->count() }})</button>
    </div>

    <!-- TAB 1: COURSES (Create / Edit / Delete + Thumbnail Upload) -->
    <section id="course-tab-courses" class="course-tab-panel space-y-6">
        <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
            <h2 class="text-sm font-bold text-slate-900">Create New Course</h2>
            <form method="POST" action="{{ route('plugins.courses.store') }}" enctype="multipart/form-data" class="grid sm:grid-cols-3 gap-3 text-xs">
                @csrf
                <input name="code" required placeholder="Course Code (e.g. AEC-101) *" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
                <input name="title" required placeholder="Course Title *" class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2">
                <div>
                    <label class="block font-semibold text-slate-600 mb-1">Category</label>
                    @if ($categories->isNotEmpty())
                        <select name="category_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 bg-white">
                            @foreach ($categories as $cat)
                                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    @else
                        <input name="category" value="IELTS Preparation" placeholder="Category Name" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                    @endif
                </div>
                <div>
                    <label class="block font-semibold text-slate-600 mb-1">Sale Price (৳) *</label>
                    <input name="price" type="number" step="0.01" required placeholder="4500" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="block font-semibold text-slate-600 mb-1">Original Price (৳)</label>
                    <input name="original_price" type="number" step="0.01" placeholder="6500" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="block font-semibold text-slate-600 mb-1">Duration (Weeks)</label>
                    <input name="duration_weeks" type="number" min="1" value="12" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="block font-semibold text-slate-600 mb-1">Upload Course Thumbnail</label>
                    <input type="file" name="thumbnail" accept="image/*" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 bg-slate-50">
                </div>
                <div>
                    <label class="block font-semibold text-slate-600 mb-1">Status</label>
                    <select name="status" class="w-full rounded-lg border border-slate-300 px-3 py-2 bg-white">
                        <option value="published">Published</option>
                        <option value="draft">Draft</option>
                    </select>
                </div>
                <textarea name="description" rows="2" placeholder="Course Description..." class="sm:col-span-3 rounded-lg border border-slate-300 px-3 py-2"></textarea>
                <div class="sm:col-span-3 text-right">
                    <button type="submit" class="px-5 py-2 rounded-lg bg-[#0284C7] hover:bg-[#0369A1] text-white font-bold">Create Course</button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
            <h2 class="text-sm font-bold text-slate-900">Manage Courses</h2>
            <div class="space-y-4">
                @forelse ($courses as $course)
                    <div class="border border-slate-200 rounded-xl p-4 space-y-3 text-xs">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                @if ($course->thumbnail)
                                    <img src="{{ $course->thumbnail }}" alt="{{ $course->title }}" class="w-16 h-12 rounded-lg object-cover border">
                                @endif
                                <div>
                                    <div class="font-bold text-sm text-slate-900">{{ $course->title }}</div>
                                    <div class="text-slate-500 font-mono">{{ $course->code }} &middot; {{ $course->category ?? 'General' }} &middot; ৳{{ number_format((float) $course->price) }} &middot; {{ $course->status }}</div>
                                </div>
                            </div>
                            <form method="POST" action="{{ route('plugins.courses.destroy', $course) }}" onsubmit="return confirm('Delete this course?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-700 font-bold hover:bg-rose-50">Delete</button>
                            </form>
                        </div>

                        <details class="pt-2 border-t border-slate-100">
                            <summary class="cursor-pointer font-semibold text-[#0284C7]">Edit / Update Course &amp; Thumbnail</summary>
                            <form method="POST" action="{{ route('plugins.courses.update', $course) }}" enctype="multipart/form-data" class="mt-3 grid sm:grid-cols-3 gap-3">
                                @csrf
                                @method('PUT')
                                <input name="code" required value="{{ $course->code }}" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
                                <input name="title" required value="{{ $course->title }}" class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2">
                                <input name="category" value="{{ $course->category }}" placeholder="Category" class="rounded-lg border border-slate-300 px-3 py-2">
                                <input name="price" type="number" step="0.01" required value="{{ $course->price }}" class="rounded-lg border border-slate-300 px-3 py-2">
                                <input name="original_price" type="number" step="0.01" value="{{ $course->original_price }}" placeholder="Original Price" class="rounded-lg border border-slate-300 px-3 py-2">
                                <input name="duration_weeks" type="number" min="1" value="{{ $course->duration_weeks ?? 12 }}" class="rounded-lg border border-slate-300 px-3 py-2">
                                <input type="file" name="thumbnail" accept="image/*" class="rounded-lg border border-slate-300 px-2 py-1.5 bg-slate-50">
                                <select name="status" class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                                    @foreach (['published' => 'Published', 'draft' => 'Draft', 'archived' => 'Archived'] as $st => $lbl)
                                        <option value="{{ $st }}" @selected($course->status === $st)>{{ $lbl }}</option>
                                    @endforeach
                                </select>
                                <textarea name="description" rows="2" class="sm:col-span-3 rounded-lg border border-slate-300 px-3 py-2">{{ $course->description }}</textarea>
                                <div class="sm:col-span-3 text-right">
                                    <button type="submit" class="px-4 py-1.5 rounded-lg bg-slate-900 text-white font-bold">Update Course</button>
                                </div>
                            </form>
                        </details>
                    </div>
                @empty
                    <p class="text-xs text-slate-400">No courses created yet.</p>
                @endforelse
            </div>
        </div>
    </section>

    <!-- TAB 2: CATEGORIES CRUD -->
    <section id="course-tab-categories" class="course-tab-panel hidden space-y-6">
        <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
            <h2 class="text-sm font-bold text-slate-900">Create Category</h2>
            <form method="POST" action="{{ route('plugins.courses.categories.store') }}" class="grid sm:grid-cols-4 gap-3 text-xs">
                @csrf
                <input name="name" required placeholder="Category Name (e.g. IELTS) *" class="rounded-lg border border-slate-300 px-3 py-2">
                <input name="slug" placeholder="Slug (optional)" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
                <input name="description" placeholder="Short Description" class="rounded-lg border border-slate-300 px-3 py-2">
                <button type="submit" class="rounded-lg bg-[#0284C7] hover:bg-[#0369A1] text-white font-bold px-4 py-2">Add Category</button>
            </form>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
            <h2 class="text-sm font-bold text-slate-900">Existing Categories</h2>
            <div class="space-y-3">
                @forelse ($categories as $cat)
                    <div class="border border-slate-200 rounded-xl p-4 text-xs space-y-3">
                        <div class="flex items-center justify-between">
                            <div>
                                <span class="font-bold text-sm text-slate-900">{{ $cat->name }}</span>
                                <span class="ml-2 font-mono text-slate-400">/{{ $cat->slug }}</span>
                            </div>
                            <form method="POST" action="{{ route('plugins.courses.categories.destroy', $cat) }}" onsubmit="return confirm('Delete this category?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="px-3 py-1 rounded-lg border border-rose-200 text-rose-700 font-bold">Delete</button>
                            </form>
                        </div>
                        <form method="POST" action="{{ route('plugins.courses.categories.update', $cat) }}" class="grid sm:grid-cols-4 gap-2 pt-2 border-t border-slate-100">
                            @csrf
                            @method('PUT')
                            <input name="name" required value="{{ $cat->name }}" class="rounded-lg border border-slate-300 px-3 py-1.5">
                            <input name="slug" required value="{{ $cat->slug }}" class="rounded-lg border border-slate-300 px-3 py-1.5 font-mono">
                            <input name="description" value="{{ $cat->description }}" placeholder="Description" class="rounded-lg border border-slate-300 px-3 py-1.5">
                            <div class="flex items-center justify-between gap-2">
                                <label class="inline-flex items-center gap-1 font-semibold">
                                    <input type="checkbox" name="is_active" value="1" @checked($cat->is_active)> Active
                                </label>
                                <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-900 text-white font-bold">Save</button>
                            </div>
                        </form>
                    </div>
                @empty
                    <p class="text-xs text-slate-400">No categories added yet.</p>
                @endforelse
            </div>
        </div>
    </section>

    <!-- TAB 3: LIVE CLASSES -->
    <section id="course-tab-live" class="course-tab-panel hidden space-y-6">
        <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
            <h2 class="text-sm font-bold text-slate-900">Schedule / Go Live Class</h2>
            @if ($courses->isEmpty())
                <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-3">Create a course first before scheduling a live class.</p>
            @else
                <form method="POST" action="{{ route('plugins.courses.live.store') }}" class="grid sm:grid-cols-3 gap-3 text-xs">
                    @csrf
                    <select name="course_id" required class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                        @foreach ($courses as $c)
                            <option value="{{ $c->id }}">{{ $c->title }}</option>
                        @endforeach
                    </select>
                    <input name="title" required placeholder="Live Session Title *" class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2">
                    <input name="instructor_name" value="Engr. Mahbubul Alom" placeholder="Instructor Name" class="rounded-lg border border-slate-300 px-3 py-2">
                    <select name="platform" required class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                        <option value="zoom">Zoom</option>
                        <option value="google_meet">Google Meet</option>
                        <option value="teams">Microsoft Teams</option>
                    </select>
                    <input name="starts_at" type="datetime-local" required class="rounded-lg border border-slate-300 px-3 py-2">
                    <input name="meeting_url" type="url" required placeholder="https://zoom.us/j/... or https://meet.google.com/..." class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2">
                    <button type="submit" class="rounded-lg bg-[#0284C7] hover:bg-[#0369A1] text-white font-bold px-4 py-2">Schedule Live Class</button>
                </form>
            @endif
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
            <h2 class="text-sm font-bold text-slate-900">Live Classes &amp; Recordings</h2>
            <div class="space-y-3">
                @forelse ($liveClasses as $live)
                    <div class="border border-slate-200 rounded-xl p-4 text-xs space-y-3">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                            <div>
                                <div class="font-bold text-sm text-slate-900">{{ $live->title }}</div>
                                <div class="text-slate-500">{{ $live->course?->title ?? 'Course' }} &middot; {{ strtoupper($live->platform) }} &middot; {{ $live->starts_at }} &middot; <strong class="uppercase text-[#0284C7]">{{ $live->status }}</strong></div>
                            </div>
                            <form method="POST" action="{{ route('plugins.courses.live.destroy', $live) }}" onsubmit="return confirm('Delete this live session?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="px-3 py-1 rounded-lg border border-rose-200 text-rose-700 font-bold">Delete</button>
                            </form>
                        </div>

                        <form method="POST" action="{{ route('plugins.courses.live.update', $live) }}" class="grid sm:grid-cols-3 gap-2 pt-2 border-t border-slate-100">
                            @csrf
                            @method('PUT')
                            <input name="title" required value="{{ $live->title }}" class="rounded-lg border border-slate-300 px-3 py-1.5">
                            <input name="instructor_name" value="{{ $live->instructor_name }}" class="rounded-lg border border-slate-300 px-3 py-1.5">
                            <select name="status" class="rounded-lg border border-slate-300 px-3 py-1.5 bg-white">
                                @foreach (['upcoming' => 'Upcoming', 'live' => 'LIVE NOW', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $st => $lbl)
                                    <option value="{{ $st }}" @selected($live->status === $st)>{{ $lbl }}</option>
                                @endforeach
                            </select>
                            <select name="platform" class="rounded-lg border border-slate-300 px-3 py-1.5 bg-white">
                                @foreach (['zoom' => 'Zoom', 'google_meet' => 'Google Meet', 'teams' => 'MS Teams'] as $pf => $pl)
                                    <option value="{{ $pf }}" @selected($live->platform === $pf)>{{ $pl }}</option>
                                @endforeach
                            </select>
                            <input name="meeting_url" type="url" required value="{{ $live->meeting_url }}" class="rounded-lg border border-slate-300 px-3 py-1.5">
                            <input name="recording_url" type="url" value="{{ $live->recording_url }}" placeholder="Recording Replay URL" class="rounded-lg border border-slate-300 px-3 py-1.5">
                            <input name="starts_at" type="datetime-local" required value="{{ date('Y-m-d\TH:i', strtotime((string) $live->starts_at)) }}" class="rounded-lg border border-slate-300 px-3 py-1.5">
                            <div class="sm:col-span-2 text-right">
                                <button type="submit" class="px-4 py-1.5 rounded-lg bg-slate-900 text-white font-bold">Update Live Class</button>
                            </div>
                        </form>
                    </div>
                @empty
                    <p class="text-xs text-slate-400">No live classes scheduled yet.</p>
                @endforelse
            </div>
        </div>
    </section>
</div>

<script>
(() => {
    const buttons = document.querySelectorAll('.course-tab-btn');
    const panels = document.querySelectorAll('.course-tab-panel');
    buttons.forEach(btn => {
        btn.addEventListener('click', () => {
            const target = btn.getAttribute('data-tab');
            buttons.forEach(b => {
                b.className = 'course-tab-btn px-4 py-2 rounded-lg text-xs font-bold bg-white border border-slate-200 text-slate-700';
            });
            btn.className = 'course-tab-btn px-4 py-2 rounded-lg text-xs font-bold bg-[#0284C7] text-white';
            panels.forEach(p => {
                p.classList.toggle('hidden', p.id !== 'course-tab-' + target);
            });
        });
    });
})();
</script>
@endsection
