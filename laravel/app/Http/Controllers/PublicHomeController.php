<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class PublicHomeController extends Controller
{
    public function index(): View
    {
        $stats = [
            'students' => Schema::hasTable('students') ? DB::table('students')->count() : 0,
            'courses' => Schema::hasTable('courses') ? DB::table('courses')->where('status', 'published')->count() : 0,
            'teachers' => Schema::hasTable('teachers') ? DB::table('teachers')->count() : 0,
            'lessons' => Schema::hasTable('course_lessons') ? DB::table('course_lessons')->count() : 0,
        ];

        $sliders = collect();
        $leftReviews = collect();
        $rightReviews = collect();

        if (Schema::hasTable('cms_sliders')) {
            $sliders = DB::table('cms_sliders')
                ->where('key_name', 'hero')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();

            $leftReviews = DB::table('cms_sliders')
                ->where('key_name', 'testimonial_left')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();

            $rightReviews = DB::table('cms_sliders')
                ->where('key_name', 'testimonial_right')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();
        }

        $homepage = [
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

        if (Schema::hasTable('system_settings')) {
            $raw = DB::table('system_settings')->where('key', 'homepage_customizer')->value('value');
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $homepage = array_merge($homepage, $decoded);
                }
            }
        }

        return view('public.home', compact('stats', 'sliders', 'leftReviews', 'rightReviews', 'homepage'));
    }

    public function customPage(string $slug): View
    {
        if (!Schema::hasTable('cms_pages')) {
            abort(404);
        }

        $page = DB::table('cms_pages')
            ->where('slug', $slug)
            ->where('is_published', true)
            ->first();

        if ($page === null) {
            abort(404);
        }

        $blocks = json_decode((string) ($page->blocks_json ?? '[]'), true);
        $body = is_array($blocks) ? (string) ($blocks[0]['content'] ?? '') : '';

        return view('public.custom-page', compact('page', 'body'));
    }

    public function courses(Request $request): View
    {
        $activeCategory = trim((string) $request->query('category', 'all'));

        $courses = collect();
        if (Schema::hasTable('courses')) {
            $courses = DB::table('courses')
                ->where('status', 'published')
                ->orderByDesc('id')
                ->get();
        }

        if ($courses->isEmpty()) {
            $courses = collect([
                (object) [
                    'id' => 1,
                    'title' => 'IELTS Academic & GT Complete Masterclass (Band 7.5+ Target)',
                    'category' => 'IELTS Preparation',
                    'duration_weeks' => 12,
                    'price' => 4500,
                    'original_price' => 6500,
                    'description' => 'Comprehensive 4-module IELTS batch covering Listening traps, Reading skimming, Writing Task 1 & 2, and live 1-on-1 Speaking mocks.',
                    'thumbnail' => asset('assets/images/course_ielts_masterclass_1794552235026.jpg'),
                ],
                (object) [
                    'id' => 2,
                    'title' => 'Spoken English & Real-Life Fluency Bootcamp',
                    'category' => 'Spoken English',
                    'duration_weeks' => 8,
                    'price' => 2500,
                    'original_price' => 4000,
                    'description' => 'Overcome speaking hesitation with IPA phonetics, daily partner speaking rooms, workplace scripts, and native collocations.',
                    'thumbnail' => asset('assets/images/course_spoken_fluency_1794552235255.jpg'),
                ],
                (object) [
                    'id' => 3,
                    'title' => 'Zero-to-Pro English Grammar & Freehand Writing',
                    'category' => 'Grammar Lab',
                    'duration_weeks' => 10,
                    'price' => 2200,
                    'original_price' => 3500,
                    'description' => 'Master clause synthesis, tense timelines, newspaper translation, and error-free academic paragraph composition.',
                    'thumbnail' => asset('assets/images/course_grammar_bundle_1794552235221.jpg'),
                ],
            ]);
        }

        $categories = $courses->pluck('category')->filter()->unique()->values();
        $filteredCourses = $activeCategory === 'all' || $activeCategory === ''
            ? $courses
            : $courses->filter(fn ($c) => strcasecmp((string) ($c->category ?? ''), $activeCategory) === 0)->values();

        return view('public.courses', compact('courses', 'categories', 'filteredCourses', 'activeCategory'));
    }

    public function learn(): View
    {
        $lessons = [];
        if (Schema::hasTable('cms_posts')) {
            $posts = DB::table('cms_posts')->where('is_published', true)->orderByDesc('id')->get();
            foreach ($posts as $post) {
                $lessons[] = [
                    'title' => $post->title,
                    'category' => $post->category ?: 'Article',
                    'duration' => 'Article',
                    'instructor' => $post->author ?: "Alom's English Center",
                    'thumbnail' => $post->thumbnail_path ?: asset('assets/images/hero_educator_studio_1794552233836.jpg'),
                ];
            }
        }

        if (empty($lessons)) {
            $lessons = [
                [
                    'title' => 'How to Think in English Without Translating',
                    'category' => 'Spoken Fluency',
                    'duration' => '18:45',
                    'instructor' => 'Engr. Mahbubul Alom',
                    'thumbnail' => asset('assets/images/hero_educator_studio_1794552233836.jpg'),
                ],
                [
                    'title' => 'IELTS Writing Task 2: Band 8 Essay Blueprint',
                    'category' => 'IELTS Writing',
                    'duration' => '24:10',
                    'instructor' => 'Nusrat Jahan',
                    'thumbnail' => asset('assets/images/course_ielts_masterclass_1794552235026.jpg'),
                ],
                [
                    'title' => 'IPA Sound Clinic & Pronunciation Clarity',
                    'category' => 'Phonetics',
                    'duration' => '15:30',
                    'instructor' => 'Tanvir Ahmed',
                    'thumbnail' => asset('assets/images/course_spoken_fluency_1794552235255.jpg'),
                ],
                [
                    'title' => 'Complex Sentence Logic & Freehand Translation',
                    'category' => 'Grammar Lab',
                    'duration' => '21:15',
                    'instructor' => 'Rafiqul Islam',
                    'thumbnail' => asset('assets/images/course_grammar_bundle_1794552235221.jpg'),
                ],
            ];
        }

        return view('public.learn', compact('lessons'));
    }

    public function instructors(): View
    {
        $instructors = [
            [
                'name' => 'Engr. Mahbubul Alom',
                'subject' => 'IELTS & Method Architect',
                'role' => "Founder & Lead Mentor, Alom's English Center",
                'credentials' => 'IELTS Band 8.5 · 15+ Years Teaching Excellence',
                'avatar' => asset('assets/images/hero_educator_studio_1794552233836.jpg'),
            ],
            [
                'name' => 'Tanvir Ahmed',
                'subject' => 'Spoken & Phonetics',
                'role' => 'Senior Fluency & Pronunciation Trainer',
                'credentials' => 'BA & MA in English, DU · CELTA Certified',
                'avatar' => asset('assets/images/instructor_tanvir_1794552825904.jpg'),
            ],
            [
                'name' => 'Nusrat Jahan',
                'subject' => 'Academic Writing & Grammar',
                'role' => 'IELTS Writing Task 1 & 2 Specialist',
                'credentials' => 'TESOL Specialist · Band 8.0 Writing Mentor',
                'avatar' => asset('assets/images/instructor_nusrat_1794552825686.jpg'),
            ],
            [
                'name' => 'Shamim Reza',
                'subject' => 'Business & Career English',
                'role' => 'Mock Evaluation & Career Skills Lead',
                'credentials' => 'Corporate Communication & Interview Coach',
                'avatar' => asset('assets/images/instructor_shamim_1794552825944.jpg'),
            ],
            [
                'name' => 'Farhana Yasmin',
                'subject' => 'Reading & Listening',
                'role' => 'IELTS Academic Reading & Trap Strategy Lead',
                'credentials' => 'MA in Applied Linguistics · Band 8.5 Scorer',
                'avatar' => asset('assets/images/instructor_nusrat_1794552825686.jpg'),
            ],
            [
                'name' => 'Rafiqul Islam',
                'subject' => 'Grammar & Freehand Writing',
                'role' => 'Senior Foundation Grammar & Sentence Craft Mentor',
                'credentials' => '12+ Years HSC, Admission & BCS English Specialist',
                'avatar' => asset('assets/images/instructor_tanvir_1794552825904.jpg'),
            ],
        ];

        return view('public.instructors', compact('instructors'));
    }

    public function exams(): View
    {
        $exams = collect();
        if (Schema::hasTable('exams')) {
            $exams = DB::table('exams')->orderByDesc('id')->get();
        }

        if ($exams->isEmpty()) {
            $exams = collect([
                (object) ['id' => 1, 'title' => 'IELTS Academic Reading Full Mock Test 01', 'category' => 'IELTS Mock', 'duration_minutes' => 60, 'total_marks' => 40],
                (object) ['id' => 2, 'title' => 'Spoken English Fluency & Pronunciation Check', 'category' => 'Spoken', 'duration_minutes' => 25, 'total_marks' => 30],
                (object) ['id' => 3, 'title' => 'Grammar & Freehand Sentence Diagnostic', 'category' => 'Grammar Lab', 'duration_minutes' => 35, 'total_marks' => 50],
            ]);
        }

        return view('public.exams', compact('exams'));
    }

    public function products(): View
    {
        $products = [
            [
                'title' => 'Complete IELTS Band 8+ Printed Books Kit (Set of 4)',
                'category' => 'Printed Books',
                'price' => 850,
                'original_price' => 1200,
                'badge' => 'Bestseller',
                'summary' => '4 printed spiral-bound volumes covering Listening, Reading, Writing Task 1/2, and Speaking Cue Cards.',
                'image' => asset('assets/images/product_ielts_books_kit_1794553908137.jpg'),
            ],
            [
                'title' => 'Spoken English & Collocations Flashcard Box',
                'category' => 'Study Kit',
                'price' => 490,
                'original_price' => 690,
                'badge' => 'Popular',
                'summary' => '300 color-coded pocket flashcards with phonetic transcription and daily speaking prompts.',
                'image' => asset('assets/images/product_spoken_flashcards_box_1794553909303.jpg'),
            ],
            [
                'title' => 'Academy Study Desk Flask & Planner Combo',
                'category' => 'Merchandise',
                'price' => 650,
                'original_price' => 900,
                'badge' => 'Limited',
                'summary' => 'Insulated matte stainless flask paired with a 90-day IELTS daily target & vocabulary tracker notebook.',
                'image' => asset('assets/images/product_academy_flask_combo_1794553909652.jpg'),
            ],
        ];

        return view('public.products', compact('products'));
    }

    public function ebooks(Request $request): View
    {
        $allBooks = $this->catalogEbooks();
        $activeCategory = trim((string) $request->query('category', 'all'));
        $priceFilter = trim((string) $request->query('type', 'all'));

        $categories = $allBooks->pluck('category')->unique()->values();
        $catFiltered = $activeCategory === 'all' || $activeCategory === ''
            ? $allBooks
            : $allBooks->filter(fn ($b) => strcasecmp($b['category'], $activeCategory) === 0)->values();

        $freeCount = $catFiltered->where('is_free', true)->count();
        $premiumCount = $catFiltered->where('is_free', false)->count();

        $visibleBooks = match ($priceFilter) {
            'free' => $catFiltered->where('is_free', true)->values(),
            'premium' => $catFiltered->where('is_free', false)->values(),
            default => $catFiltered,
        };

        return view('public.ebooks', compact(
            'allBooks',
            'categories',
            'activeCategory',
            'priceFilter',
            'catFiltered',
            'freeCount',
            'premiumCount',
            'visibleBooks'
        ));
    }

    public function ebookShow(string $slug): View
    {
        $allBooks = $this->catalogEbooks();
        $ebook = $allBooks->firstWhere('slug', $slug) ?? $allBooks->first();

        return view('public.ebook-show', compact('ebook'));
    }

    public function result(Request $request): View
    {
        $queryCode = trim((string) $request->query('code', ''));
        $lookupResult = null;
        $lookupError = null;

        if ($queryCode !== '') {
            if (Schema::hasTable('certificates')) {
                $lookupResult = DB::table('certificates')
                    ->where('verification_code', $queryCode)
                    ->first();
            }

            if ($lookupResult === null) {
                $lookupError = 'No verified certificate or board result matched "' . $queryCode . '". Please check your registration or verification ID.';
            }
        }

        return view('public.result', compact('queryCode', 'lookupResult', 'lookupError'));
    }

    private function catalogEbooks(): \Illuminate\Support\Collection
    {
        return collect([
            [
                'slug' => 'ielts-writing-task-2-band-8-blueprint',
                'title' => 'IELTS Writing Task 2 Band 8.0 Blueprint',
                'category' => 'IELTS Writing',
                'summary' => 'Complete essay structures, linking frameworks, and 15 model Band 8+ essays with examiner commentary.',
                'price' => 0,
                'original_price' => 0,
                'is_free' => true,
                'pages' => 64,
                'gradient' => 'from-[#0B5CD5] via-[#0284C7] to-[#032D42]',
                'topics' => [
                    'Opinion & Discussion Essay Templates',
                    'Cohesion & Lexical Resource Booster',
                    'Common Band-Lowering Mistakes',
                ],
            ],
            [
                'slug' => 'spoken-english-daily-fluency-collocations',
                'title' => 'Spoken English Daily Fluency & Collocations',
                'category' => 'Spoken English',
                'summary' => '400+ practical conversation dialogues, native idioms, and workplace speaking scripts for instant fluency.',
                'price' => 0,
                'original_price' => 0,
                'is_free' => true,
                'pages' => 52,
                'gradient' => 'from-[#065F46] via-[#0D9488] to-[#022C22]',
                'topics' => [
                    'Natural Greetings & Small Talk',
                    'Interview & Presentation Phrases',
                    'Pronunciation Stress Rules',
                ],
            ],
            [
                'slug' => 'zero-to-pro-english-grammar-handbook',
                'title' => 'Zero-to-Pro English Grammar Handbook',
                'category' => 'Grammar Lab',
                'summary' => 'Visual charts for all 12 tenses, conditional clauses, modifiers, and free-hand translation mastery.',
                'price' => 99,
                'original_price' => 199,
                'is_free' => false,
                'pages' => 112,
                'gradient' => 'from-[#8B5CF6] via-[#C026D3] to-[#581C87]',
                'topics' => [
                    'Tense Timeline Matrix',
                    'Active-Passive Transformation',
                    '100+ Error Correction Exercises',
                ],
            ],
            [
                'slug' => 'academic-reading-vocabulary-vault',
                'title' => '1000+ Academic Reading & Vocabulary Vault',
                'category' => 'Vocabulary',
                'summary' => 'High-frequency IELTS reading synonyms, paraphrasing tables, and contextual flashcards.',
                'price' => 129,
                'original_price' => 249,
                'is_free' => false,
                'pages' => 96,
                'gradient' => 'from-[#1E3A8A] via-[#4338CA] to-[#172554]',
                'topics' => [
                    'True/False/Not Given Strategy',
                    'Heading Match Shortcuts',
                    '800 Academic Word Families',
                ],
            ],
        ]);
    }
}
