@extends('layouts.app')
@section('title', "Alom's English Center — AEC LMS")
@section('content')
@php
    $heroSlides = $sliders->isNotEmpty() ? $sliders->map(fn ($s) => [
        'title' => $s->title,
        'subtitle' => $s->subtitle ?? '',
        'image' => $s->image_path ?: asset('assets/images/hero_educator_studio_1794552233836.jpg'),
        'cta_url' => $s->cta_url ?: '#learning-paths',
    ])->values()->all() : [
        [
            'title' => 'IELTS & English All in One',
            'subtitle' => 'Basic to Pro · Structured Live & Recorded Batches',
            'image' => asset('assets/images/hero_educator_studio_1794552233836.jpg'),
            'cta_url' => '#learning-paths',
        ],
        [
            'title' => 'IELTS Academic & GT Masterclass',
            'subtitle' => 'Target Band 8.0+ · Weekly Mock Tests & Writing Review',
            'image' => asset('assets/images/course_ielts_masterclass_1794552235026.jpg'),
            'cta_url' => '#learning-paths',
        ],
        [
            'title' => 'Spoken English & Fluency Bootcamp',
            'subtitle' => 'Phonetics, Pronunciation & Daily Speaking Practice',
            'image' => asset('assets/images/course_spoken_fluency_1794552235255.jpg'),
            'cta_url' => '#learning-paths',
        ],
    ];

    $leftCards = $leftReviews->isNotEmpty() ? $leftReviews : collect([
        (object) ['title' => 'Mahmud Hasan', 'subtitle' => 'Weekly mock interviews and line-by-line Writing feedback helped me progress from Band 6.0 to 8.0.', 'meta' => 'Verified Student'],
        (object) ['title' => 'Afrina Jahan', 'subtitle' => 'Every grammar concept from tense logic to free-hand composition is explained with crystal clarity.', 'meta' => 'Verified Student'],
        (object) ['title' => 'Borhan Uddin', 'subtitle' => 'Structured lessons and daily practice drills built my spoken fluency inside two months.', 'meta' => 'Verified Student'],
        (object) ['title' => 'Sadia Afrin', 'subtitle' => 'Clear guidance, responsive instructors, and reliable exam evaluation at every step.', 'meta' => 'Verified Student'],
    ]);

    $rightCards = $rightReviews->isNotEmpty() ? $rightReviews : collect([
        (object) ['title' => 'Zidni Islam', 'subtitle' => 'The online classroom, lecture sheets, and diagnostic exams make daily revision effortless.', 'meta' => 'Verified Student'],
        (object) ['title' => 'Sumaiya Saima', 'subtitle' => 'Phonetics sessions and live speaking clubs removed my hesitation during interviews.', 'meta' => 'Verified Student'],
        (object) ['title' => 'Fakhrul Islam', 'subtitle' => 'Best structured English academy with measurable progress tracking across every module.', 'meta' => 'Verified Student'],
        (object) ['title' => 'Tasannun Tarin', 'subtitle' => 'Reading strategy workshops helped me solve passage traps rapidly and accurately.', 'meta' => 'Verified Student'],
    ]);
@endphp

@include('public.partials.navbar')

<!-- 2. DEEP TEAL HERO STAGE: 3-CARD PEEK CAROUSEL + DUAL BENTO CARDS -->
<section class="bg-gradient-to-b from-[#022D41] via-[#032638] to-[#021E2E] text-white pt-8 pb-14 px-4 sm:px-6 overflow-hidden">
    <div class="max-w-[1280px] mx-auto space-y-8">

        <!-- 3-Card Peek Promotional Carousel -->
        @if ($homepage['show_slider'] ?? true)
        <div class="flex items-center justify-center gap-4 lg:gap-6">
            <!-- Left Peek Card -->
            <button type="button" id="hero-prev-peek" aria-label="Previous Slide" class="hidden lg:block w-[15%] h-[320px] rounded-2xl overflow-hidden border border-white/15 opacity-35 hover:opacity-60 transition-opacity shrink-0 relative bg-[#021C2B]">
                <img id="hero-prev-img" src="{{ $heroSlides[count($heroSlides) - 1]['image'] }}" alt="Previous preview" class="w-full h-full object-cover">
                <div class="absolute inset-0 bg-[#032D42]/50"></div>
            </button>

            <!-- Center Main Hero Slide -->
            <div class="w-full lg:w-[66%] max-w-[820px] relative rounded-2xl overflow-hidden border border-white/15 bg-[#021C2B] shadow-2xl">
                <div class="relative aspect-[16/9] sm:h-[380px] lg:h-[400px] w-full">
                    <img id="hero-main-img" src="{{ $heroSlides[0]['image'] }}" alt="{{ $heroSlides[0]['title'] }}" class="w-full h-full object-cover">
                    <div class="absolute inset-0 bg-gradient-to-t from-[#021824]/90 via-[#021824]/30 to-transparent"></div>
                    <div class="absolute inset-x-0 bottom-12 px-6 sm:px-10 text-center space-y-1.5">
                        <h1 id="hero-main-title" class="text-2xl sm:text-4xl font-extrabold tracking-tight text-white drop-shadow">
                            {{ $heroSlides[0]['title'] }}
                        </h1>
                        <p id="hero-main-subtitle" class="text-xs sm:text-sm font-semibold text-amber-300">
                            {{ $heroSlides[0]['subtitle'] }}
                        </p>
                    </div>
                </div>

                <!-- Circular Carousel Controls & Dots -->
                <button type="button" id="hero-prev-btn" aria-label="Previous slide" class="absolute left-4 bottom-4 w-8 h-8 rounded-full bg-slate-950/70 hover:bg-[#0284C7] text-white border border-white/25 flex items-center justify-center transition-colors">
                    &lsaquo;
                </button>
                <button type="button" id="hero-next-btn" aria-label="Next slide" class="absolute right-4 bottom-4 w-8 h-8 rounded-full bg-slate-950/70 hover:bg-[#0284C7] text-white border border-white/25 flex items-center justify-center transition-colors">
                    &rsaquo;
                </button>
                <div id="hero-dots" class="absolute bottom-5 left-1/2 -translate-x-1/2 flex items-center gap-1.5">
                    @foreach ($heroSlides as $i => $s)
                        <span data-dot="{{ $i }}" class="h-1.5 rounded-full transition-all {{ $i === 0 ? 'w-5 bg-white' : 'w-1.5 bg-white/40' }}"></span>
                    @endforeach
                </div>
            </div>

            <!-- Right Peek Card -->
            <button type="button" id="hero-next-peek" aria-label="Next Slide" class="hidden lg:block w-[15%] h-[320px] rounded-2xl overflow-hidden border border-white/15 opacity-35 hover:opacity-60 transition-opacity shrink-0 relative bg-[#021C2B]">
                <img id="hero-next-img" src="{{ $heroSlides[1 % count($heroSlides)]['image'] }}" alt="Next preview" class="w-full h-full object-cover">
                <div class="absolute inset-0 bg-[#032D42]/50"></div>
            </button>
        </div>
        @endif

        <!-- Dual Bento Cards -->
        @if ($homepage['show_batches'] ?? true)
        <div id="ongoing-batches" class="max-w-[1040px] mx-auto grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Left Bento: Ongoing Batches -->
            <div class="rounded-2xl p-6 sm:p-7 border border-[#14B8A6]/25 shadow-xl flex flex-col justify-between space-y-5" style="background: linear-gradient(135deg, #064844 0%, #032833 100%)">
                <div class="space-y-2">
                    <span class="inline-block px-2.5 py-0.5 rounded-md bg-white/10 border border-white/15 text-[11px] font-semibold text-[#A7F3D0]">Online Course</span>
                    <h2 class="text-xl sm:text-2xl font-extrabold text-white tracking-tight">{{ $homepage['batches_title'] ?? 'Online Batches are ongoing!' }}</h2>
                    <p class="text-xs sm:text-sm font-medium text-[#2DD4BF]">{{ $homepage['batches_subtitle'] ?? 'Book your seat now!' }}</p>
                </div>

                <div class="space-y-3">
                    <a href="{{ route('public.courses') }}" class="bg-[#0B2A35]/90 hover:bg-[#103643] border border-white/10 rounded-xl p-3.5 flex items-center gap-3.5 transition-colors">
                        <div class="w-10 h-10 rounded-lg bg-[#10B981] text-white flex items-center justify-center shrink-0 font-bold text-sm">A</div>
                        <div class="min-w-0">
                            <div class="text-sm font-bold text-white truncate">IELTS Academic &amp; GT</div>
                            <div class="text-xs text-slate-300 truncate">Band 7.5+ Target Preparation</div>
                        </div>
                    </a>
                    <a href="{{ route('public.courses') }}" class="bg-[#0B2A35]/90 hover:bg-[#103643] border border-white/10 rounded-xl p-3.5 flex items-center gap-3.5 transition-colors">
                        <div class="w-10 h-10 rounded-lg bg-[#10B981] text-white flex items-center justify-center shrink-0 font-bold text-sm">S</div>
                        <div class="min-w-0">
                            <div class="text-sm font-bold text-white truncate">Spoken &amp; Grammar Foundation</div>
                            <div class="text-xs text-slate-300 truncate">Basic to Pro Live Batches</div>
                        </div>
                    </a>
                </div>

                <div>
                    <a href="{{ route('public.courses') }}" class="inline-flex items-center gap-1.5 text-xs sm:text-sm font-semibold text-[#2DD4BF] hover:text-[#5EEAD4]">
                        <span>See All Courses</span><span>&rarr;</span>
                    </a>
                </div>
            </div>

            <!-- Right Bento: See the stats! -->
            <div class="rounded-2xl p-6 sm:p-7 border border-[#38BDF8]/25 shadow-xl flex flex-col justify-between space-y-5" style="background: linear-gradient(135deg, #073E66 0%, #03233E 100%)">
                <div class="space-y-2">
                    <span class="inline-block px-2.5 py-0.5 rounded-md bg-white/10 border border-white/15 text-[11px] font-semibold text-[#BAE6FD]">Online Course</span>
                    <h2 class="text-xl sm:text-2xl font-extrabold text-white tracking-tight">{{ $homepage['stats_title'] ?? 'See the stats!' }}</h2>
                    <p class="text-xs sm:text-sm font-medium text-[#38BDF8]">{{ $homepage['stats_subtitle'] ?? 'The trust we are building!' }}</p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-[#0C2B47]/90 border border-white/10 rounded-xl p-3.5 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-[#0EA5E9] text-white flex items-center justify-center shrink-0 font-extrabold text-xs">ST</div>
                        <div class="min-w-0">
                            <div class="text-base sm:text-lg font-extrabold tabular-nums text-white leading-tight">{{ number_format((int) $stats['students']) }}</div>
                            <div class="text-[11px] text-slate-300">Students</div>
                        </div>
                    </div>
                    <div class="bg-[#0C2B47]/90 border border-white/10 rounded-xl p-3.5 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-[#0EA5E9] text-white flex items-center justify-center shrink-0 font-extrabold text-xs">CR</div>
                        <div class="min-w-0">
                            <div class="text-base sm:text-lg font-extrabold tabular-nums text-white leading-tight">{{ number_format((int) $stats['courses']) }}</div>
                            <div class="text-[11px] text-slate-300">Courses</div>
                        </div>
                    </div>
                    <div class="bg-[#0C2B47]/90 border border-white/10 rounded-xl p-3.5 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-[#0EA5E9] text-white flex items-center justify-center shrink-0 font-extrabold text-xs">TR</div>
                        <div class="min-w-0">
                            <div class="text-base sm:text-lg font-extrabold tabular-nums text-white leading-tight">{{ number_format((int) $stats['teachers']) }}</div>
                            <div class="text-[11px] text-slate-300">Teachers</div>
                        </div>
                    </div>
                    <div class="bg-[#0C2B47]/90 border border-white/10 rounded-xl p-3.5 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-[#0EA5E9] text-white flex items-center justify-center shrink-0 font-extrabold text-xs">VD</div>
                        <div class="min-w-0">
                            <div class="text-base sm:text-lg font-extrabold tabular-nums text-white leading-tight">{{ number_format((int) $stats['lessons']) }}</div>
                            <div class="text-[11px] text-slate-300">Lessons</div>
                        </div>
                    </div>
                </div>

                <div>
                    <a href="{{ route('public.courses') }}" class="inline-flex items-center gap-1.5 text-xs sm:text-sm font-semibold text-[#38BDF8] hover:opacity-85">
                        <span>See All Courses</span><span>&rarr;</span>
                    </a>
                </div>
            </div>
        </div>
        @endif

    </div>
</section>

<!-- 3. CHOOSE YOUR LEARNING PATH (6 PASTEL CARDS) -->
@if ($homepage['show_learning_paths'] ?? true)
<section id="learning-paths" class="py-16 px-4 sm:px-6" style="background: linear-gradient(135deg, #EAE9FF 0%, #F2F3FF 45%, #E5F4FE 100%)">
    <div class="max-w-[1160px] mx-auto space-y-10">
        <div class="text-center max-w-2xl mx-auto space-y-2.5">
            <h2 class="text-3xl sm:text-4xl font-extrabold text-[#06B6D4] tracking-tight">{{ $homepage['paths_title'] ?? 'Choose Your Learning Path' }}</h2>
            <p class="text-xs sm:text-sm text-slate-600 leading-relaxed">
                {{ $homepage['paths_subtitle'] ?? 'Discover diverse courses across multiple disciplines designed to help you excel in your academic and admission journey.' }}
            </p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
            @foreach ([
                ['Academic Courses', 'Comprehensive academic preparation for all levels', '#D6E9FF', '#BBD8FF', '#1D63FF', 'AC', route('public.courses')],
                ['Admission Courses', 'Specialized courses for university & IELTS admissions', '#CEFCE0', '#B2F5CD', '#00A647', 'AD', route('public.courses')],
                ['Grammar & Logic', 'Advanced grammar concepts and problem solving', '#F0DEFF', '#E1C2FF', '#9333EA', 'GR', route('public.ebooks')],
                ['Spoken & Phonetics', 'Pronunciation clarity, fluency & speaking drills', '#FFE6C7', '#FFD09B', '#F94E00', 'SP', route('public.learn')],
                ['Technology & Skills', 'Modern writing, presentation & career skills', '#DDE6FF', '#C4D2FF', '#4F46E5', 'SK', route('public.exams')],
                ['Community', 'Join our supportive peer learning community', '#FFE0EE', '#FFC4DE', '#EC0075', 'CM', route('public.instructors')],
            ] as [$title, $desc, $bg, $border, $accent, $abbr, $url])
                <a href="{{ $url }}" class="rounded-2xl p-6 border transition-all duration-150 hover:-translate-y-0.5 hover:shadow-md flex flex-col justify-between space-y-5" style="background-color: {{ $bg }}; border-color: {{ $border }};">
                    <div class="space-y-4">
                        <div class="w-11 h-11 rounded-xl text-white font-extrabold text-xs flex items-center justify-center shadow-xs" style="background-color: {{ $accent }}">
                            {{ $abbr }}
                        </div>
                        <div class="space-y-1">
                            <h3 class="text-base font-extrabold" style="color: {{ $accent }}">{{ $title }}</h3>
                            <p class="text-xs text-slate-600 leading-relaxed">{{ $desc }}</p>
                        </div>
                    </div>
                    <div class="flex justify-end items-center gap-1 text-xs font-bold" style="color: {{ $accent }}">
                        <span>Explore</span><span>&rsaquo;</span>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
</section>
@endif

<!-- 4. WHAT OUR STUDENTS SAY (DUAL LEFT & RIGHT MARQUEE CAROUSELS) -->
@if ($homepage['show_reviews'] ?? true)
<section id="reviews" class="py-16 sm:py-20 text-white overflow-hidden" style="background: linear-gradient(180deg, #131D2D 0%, #0B1220 100%)">
    <div class="max-w-[1200px] mx-auto px-4 sm:px-6 mb-10 text-center space-y-3">
        <div class="text-xs font-semibold tracking-wide text-[#38BDF8]">Student Reviews</div>
        <h2 class="text-3xl sm:text-4xl font-extrabold text-white tracking-tight">{{ $homepage['reviews_title'] ?? 'What Our Students Say' }}</h2>
        <p class="text-xs sm:text-sm text-slate-300 max-w-xl mx-auto">
            {{ $homepage['reviews_subtitle'] ?? "Feedbacks from students who have transformed their learning journey with Alom's English Center." }}
        </p>
        <div class="pt-2 flex justify-center">
            <div class="inline-flex items-center gap-2.5 px-5 py-2 rounded-full bg-[#162238] border border-white/10 text-xs shadow-sm">
                <span class="text-amber-400 tracking-tight">&#9733;&#9733;&#9733;&#9733;&#9733;</span>
                <span class="font-bold text-white tabular-nums">5.0</span>
                <span class="text-slate-400">average rating</span>
            </div>
        </div>
    </div>

    <div class="marquee-wrapper relative overflow-hidden space-y-5">
        <div class="pointer-events-none absolute inset-y-0 left-0 w-10 sm:w-28 z-10" style="background: linear-gradient(90deg, #131D2D, transparent)"></div>
        <div class="pointer-events-none absolute inset-y-0 right-0 w-10 sm:w-28 z-10" style="background: linear-gradient(270deg, #131D2D, transparent)"></div>

        <!-- Row 1: Left Carousel -->
        <div class="animate-marquee-left">
            @foreach ([1, 2] as $dup)
                <div class="flex gap-5 pr-5 shrink-0">
                    @foreach ($leftCards as $card)
                        <article class="w-[330px] sm:w-[360px] shrink-0 bg-[#1B2537] border border-white/10 rounded-xl p-5 flex flex-col justify-between space-y-4 shadow-lg">
                            <div class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-amber-400 text-xs tracking-tight">&#9733;&#9733;&#9733;&#9733;&#9733;</span>
                                    <span class="text-slate-600 font-serif text-lg leading-none">&ldquo;&rdquo;</span>
                                </div>
                                <p class="text-xs sm:text-[13px] text-slate-300 leading-relaxed italic line-clamp-4">{{ $card->subtitle }}</p>
                            </div>
                            <div class="pt-3 border-t border-white/10 flex items-center gap-3">
                                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-[#0284C7] to-[#2563EB] text-white font-bold text-xs flex items-center justify-center shrink-0">
                                    {{ strtoupper(substr($card->title, 0, 2)) }}
                                </div>
                                <div class="min-w-0">
                                    <div class="text-xs font-bold text-white truncate">{{ $card->title }}</div>
                                    <div class="text-[11px] text-slate-400">Verified Student</div>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endforeach
        </div>

        <!-- Row 2: Right Carousel -->
        <div class="animate-marquee-right">
            @foreach ([1, 2] as $dup)
                <div class="flex gap-5 pr-5 shrink-0">
                    @foreach ($rightCards as $card)
                        <article class="w-[330px] sm:w-[360px] shrink-0 bg-[#1B2537] border border-white/10 rounded-xl p-5 flex flex-col justify-between space-y-4 shadow-lg">
                            <div class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-amber-400 text-xs tracking-tight">&#9733;&#9733;&#9733;&#9733;&#9733;</span>
                                    <span class="text-slate-600 font-serif text-lg leading-none">&ldquo;&rdquo;</span>
                                </div>
                                <p class="text-xs sm:text-[13px] text-slate-300 leading-relaxed italic line-clamp-4">{{ $card->subtitle }}</p>
                            </div>
                            <div class="pt-3 border-t border-white/10 flex items-center gap-3">
                                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-[#10B981] to-[#0284C7] text-white font-bold text-xs flex items-center justify-center shrink-0">
                                    {{ strtoupper(substr($card->title, 0, 2)) }}
                                </div>
                                <div class="min-w-0">
                                    <div class="text-xs font-bold text-white truncate">{{ $card->title }}</div>
                                    <div class="text-[11px] text-slate-400">Verified Student</div>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif

@include('public.partials.footer')

<script>
(() => {
    const slides = @json($heroSlides);
    if (!Array.isArray(slides) || slides.length < 2) return;
    let idx = 0;
    const mainImg = document.getElementById('hero-main-img');
    const prevImg = document.getElementById('hero-prev-img');
    const nextImg = document.getElementById('hero-next-img');
    const mainTitle = document.getElementById('hero-main-title');
    const mainSub = document.getElementById('hero-main-subtitle');
    const dots = document.querySelectorAll('#hero-dots [data-dot]');

    const render = () => {
        const prev = (idx - 1 + slides.length) % slides.length;
        const next = (idx + 1) % slides.length;
        mainImg.src = slides[idx].image;
        mainImg.alt = slides[idx].title;
        prevImg.src = slides[prev].image;
        nextImg.src = slides[next].image;
        mainTitle.textContent = slides[idx].title;
        mainSub.textContent = slides[idx].subtitle;
        dots.forEach((d, i) => {
            d.className = 'h-1.5 rounded-full transition-all ' + (i === idx ? 'w-5 bg-white' : 'w-1.5 bg-white/40');
        });
    };

    const step = (delta) => { idx = (idx + delta + slides.length) % slides.length; render(); };
    document.getElementById('hero-prev-btn')?.addEventListener('click', () => step(-1));
    document.getElementById('hero-next-btn')?.addEventListener('click', () => step(1));
    document.getElementById('hero-prev-peek')?.addEventListener('click', () => step(-1));
    document.getElementById('hero-next-peek')?.addEventListener('click', () => step(1));
})();
</script>
@endsection
