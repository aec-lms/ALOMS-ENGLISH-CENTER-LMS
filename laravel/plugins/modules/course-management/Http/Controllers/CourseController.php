<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\CourseManagement\Models\CourseCategory;
use Plugins\Modules\CourseManagement\Models\LiveClass;

class CourseController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $courses = Schema::hasTable('courses') ? Course::query()->latest()->paginate(20) : collect();
        $categories = Schema::hasTable('course_categories')
            ? CourseCategory::query()->orderBy('name')->get()
            : collect();
        $liveClasses = Schema::hasTable('live_classes')
            ? LiveClass::query()->with('course')->latest('starts_at')->get()
            : collect();

        return view('course-management::index', compact('courses', 'categories', 'liveClasses'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:courses,code'],
            'title' => ['required', 'string', 'max:180'],
            'category' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'numeric', 'min:0'],
            'original_price' => ['nullable', 'numeric', 'min:0'],
            'duration_weeks' => ['nullable', 'integer', 'min:1', 'max:104'],
            'branch_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:published,draft,archived'],
            'thumbnail' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'thumbnail_url' => ['nullable', 'string', 'max:255'],
        ]);

        $categoryName = $validated['category'] ?? 'General';
        $categoryId = $validated['category_id'] ?? null;
        if ($categoryId && Schema::hasTable('course_categories')) {
            $cat = CourseCategory::query()->find($categoryId);
            if ($cat) {
                $categoryName = $cat->name;
            }
        }

        $thumbPath = $request->hasFile('thumbnail')
            ? $this->storeUploadedImage($request->file('thumbnail'), 'courses')
            : ($validated['thumbnail_url'] ?? null);

        $course = Course::query()->create([
            'code' => $validated['code'],
            'title' => $validated['title'],
            'category' => $categoryName,
            'category_id' => $categoryId,
            'slug' => Str::slug($validated['title']) . '-' . Str::lower(Str::random(4)),
            'description' => $validated['description'] ?? null,
            'thumbnail' => $thumbPath,
            'price' => $validated['price'],
            'original_price' => $validated['original_price'] ?? null,
            'duration_weeks' => (int) ($validated['duration_weeks'] ?? 12),
            'branch_id' => $validated['branch_id'] ?? null,
            'status' => $validated['status'] ?? 'published',
        ]);

        $this->audit->log('course.created', 'course', (string) $course->id, ['code' => $course->code]);

        return back()->with('status', "Course '{$course->title}' created.");
    }

    public function update(Request $request, Course $course): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:courses,code,' . $course->id],
            'title' => ['required', 'string', 'max:180'],
            'category' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'numeric', 'min:0'],
            'original_price' => ['nullable', 'numeric', 'min:0'],
            'duration_weeks' => ['nullable', 'integer', 'min:1', 'max:104'],
            'status' => ['required', 'in:published,draft,archived'],
            'thumbnail' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'thumbnail_url' => ['nullable', 'string', 'max:255'],
        ]);

        $categoryName = $validated['category'] ?? $course->category ?? 'General';
        $categoryId = $validated['category_id'] ?? $course->category_id;
        if ($categoryId && Schema::hasTable('course_categories')) {
            $cat = CourseCategory::query()->find($categoryId);
            if ($cat) {
                $categoryName = $cat->name;
            }
        }

        $thumbPath = $course->thumbnail;
        if ($request->hasFile('thumbnail')) {
            $thumbPath = $this->storeUploadedImage($request->file('thumbnail'), 'courses');
        } elseif (!empty($validated['thumbnail_url'])) {
            $thumbPath = $validated['thumbnail_url'];
        }

        $course->update([
            'code' => $validated['code'],
            'title' => $validated['title'],
            'category' => $categoryName,
            'category_id' => $categoryId,
            'description' => $validated['description'] ?? $course->description,
            'thumbnail' => $thumbPath,
            'price' => $validated['price'],
            'original_price' => $validated['original_price'] ?? $course->original_price,
            'duration_weeks' => (int) ($validated['duration_weeks'] ?? $course->duration_weeks ?? 12),
            'status' => $validated['status'],
        ]);

        $this->audit->log('course.updated', 'course', (string) $course->id, ['code' => $course->code]);

        return back()->with('status', "Course '{$course->title}' updated.");
    }

    public function destroy(Course $course): RedirectResponse
    {
        $title = $course->title;
        $id = (string) $course->id;
        $course->delete();

        $this->audit->log('course.deleted', 'course', $id, ['title' => $title]);

        return back()->with('status', "Course '{$title}' deleted.");
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'alpha_dash', 'max:140', 'unique:course_categories,slug'],
            'description' => ['nullable', 'string', 'max:320'],
            'color' => ['nullable', 'string', 'max:32'],
        ]);

        $slug = !empty($validated['slug'])
            ? strtolower($validated['slug'])
            : Str::slug($validated['name']) . '-' . Str::lower(Str::random(3));

        $cat = CourseCategory::query()->create([
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'] ?? 'blue',
            'is_active' => true,
        ]);

        $this->audit->log('course_category.created', 'course_category', (string) $cat->id, ['name' => $cat->name]);

        return back()->with('status', "Category '{$cat->name}' created.");
    }

    public function updateCategory(Request $request, CourseCategory $category): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'alpha_dash', 'max:140', 'unique:course_categories,slug,' . $category->id],
            'description' => ['nullable', 'string', 'max:320'],
            'color' => ['nullable', 'string', 'max:32'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $category->update([
            'name' => $validated['name'],
            'slug' => strtolower($validated['slug']),
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'] ?? $category->color,
            'is_active' => $request->boolean('is_active'),
        ]);

        $this->audit->log('course_category.updated', 'course_category', (string) $category->id, ['name' => $category->name]);

        return back()->with('status', "Category '{$category->name}' updated.");
    }

    public function destroyCategory(CourseCategory $category): RedirectResponse
    {
        $name = $category->name;
        $id = (string) $category->id;
        $category->delete();

        $this->audit->log('course_category.deleted', 'course_category', $id, ['name' => $name]);

        return back()->with('status', "Category '{$name}' deleted.");
    }

    public function storeLiveClass(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'title' => ['required', 'string', 'max:180'],
            'instructor_name' => ['nullable', 'string', 'max:120'],
            'platform' => ['required', 'in:zoom,google_meet,teams'],
            'meeting_url' => ['required', 'url', 'max:255'],
            'starts_at' => ['required', 'date'],
        ]);

        $live = LiveClass::query()->create([
            'course_id' => $validated['course_id'],
            'title' => $validated['title'],
            'instructor_name' => $validated['instructor_name'] ?: "Engr. Mahbubul Alom",
            'platform' => $validated['platform'],
            'meeting_url' => $validated['meeting_url'],
            'starts_at' => $validated['starts_at'],
            'status' => 'upcoming',
        ]);

        $this->audit->log('live_class.scheduled', 'live_class', (string) $live->id, ['title' => $live->title]);

        return back()->with('status', "Live class '{$live->title}' scheduled.");
    }

    public function updateLiveClass(Request $request, LiveClass $liveClass): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'instructor_name' => ['nullable', 'string', 'max:120'],
            'platform' => ['required', 'in:zoom,google_meet,teams'],
            'meeting_url' => ['required', 'url', 'max:255'],
            'recording_url' => ['nullable', 'url', 'max:255'],
            'starts_at' => ['required', 'date'],
            'status' => ['required', 'in:upcoming,live,completed,cancelled'],
        ]);

        $liveClass->update($validated);

        $this->audit->log('live_class.updated', 'live_class', (string) $liveClass->id, ['status' => $liveClass->status]);

        return back()->with('status', "Live class '{$liveClass->title}' updated ({$liveClass->status}).");
    }

    public function destroyLiveClass(LiveClass $liveClass): RedirectResponse
    {
        $title = $liveClass->title;
        $id = (string) $liveClass->id;
        $liveClass->delete();

        $this->audit->log('live_class.deleted', 'live_class', $id, ['title' => $title]);

        return back()->with('status', "Live class '{$title}' removed.");
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
}
