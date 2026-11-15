@php
    $navLinks = [
        ['label' => 'Home', 'route' => 'home', 'active' => request()->routeIs('home')],
        ['label' => 'Courses', 'route' => 'public.courses', 'active' => request()->routeIs('public.courses')],
        ['label' => 'Learn', 'route' => 'public.learn', 'active' => request()->routeIs('public.learn')],
        ['label' => 'Instructors', 'route' => 'public.instructors', 'active' => request()->routeIs('public.instructors')],
        ['label' => 'Exams', 'route' => 'public.exams', 'active' => request()->routeIs('public.exams')],
        ['label' => 'Products', 'route' => 'public.products', 'active' => request()->routeIs('public.products')],
        ['label' => 'E-Books', 'route' => 'public.ebooks*', 'urlRoute' => 'public.ebooks', 'active' => request()->routeIs('public.ebooks*')],
        ['label' => 'Result', 'route' => 'public.result', 'active' => request()->routeIs('public.result')],
    ];
@endphp

<header class="w-full bg-white border-b border-slate-200/80 sticky top-0 z-40 shadow-2xs">
    <div class="max-w-[1200px] mx-auto px-4 sm:px-6 h-16 flex items-center justify-between gap-6">
        <a href="{{ route('home') }}" class="flex items-center gap-2.5 whitespace-nowrap shrink-0">
            <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-[#0284C7] to-[#4F46E5] text-white font-black text-sm flex items-center justify-center shadow-xs">
                A
            </span>
            <span class="leading-[1.05]">
                <span class="block text-[13px] sm:text-sm font-black tracking-tight text-slate-900">ALOM'S</span>
                <span class="block text-[11px] font-extrabold tracking-tight text-slate-800">ENGLISH CENTER</span>
            </span>
        </a>

        <nav class="hidden lg:flex items-center gap-7 text-[13px] font-medium text-slate-600">
            @foreach ($navLinks as $item)
                <a
                    href="{{ route($item['urlRoute'] ?? $item['route']) }}"
                    class="whitespace-nowrap shrink-0 py-1.5 transition-colors {{ $item['active'] ? 'text-[#0284C7] font-semibold' : 'hover:text-slate-900' }}"
                >
                    {{ $item['label'] }}
                </a>
            @endforeach
        </nav>

        <div class="flex items-center gap-2.5 shrink-0">
            @auth
                <a href="{{ route('admin.dashboard') }}" class="px-5 py-2 rounded-lg bg-[#EFF4FF] hover:bg-[#DBEAFE] border border-[#DBEAFE] text-[#2563EB] text-xs sm:text-[13px] font-semibold whitespace-nowrap transition-colors">
                    Portal
                </a>
            @else
                <a href="{{ route('login') }}" class="px-5 py-2 rounded-lg bg-[#EFF4FF] hover:bg-[#DBEAFE] border border-[#DBEAFE] text-[#2563EB] text-xs sm:text-[13px] font-semibold whitespace-nowrap transition-colors">
                    Login
                </a>
            @endauth

            <button
                type="button"
                id="public-mobile-menu-toggle"
                aria-label="Toggle navigation menu"
                class="lg:hidden p-2 text-slate-600 hover:text-slate-900 rounded-lg hover:bg-slate-100 transition-colors"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
        </div>
    </div>

    <div id="public-mobile-menu" class="hidden lg:hidden bg-white border-t border-slate-200 px-4 py-3 space-y-1 shadow-md">
        @foreach ($navLinks as $item)
            <a
                href="{{ route($item['urlRoute'] ?? $item['route']) }}"
                class="block px-3 py-2.5 rounded-lg text-sm font-medium transition-colors {{ $item['active'] ? 'bg-[#EEF2FF] text-[#0284C7] font-semibold' : 'text-slate-600 hover:bg-slate-50 hover:text-[#0284C7]' }}"
            >
                {{ $item['label'] }}
            </a>
        @endforeach
    </div>
</header>

<script>
(() => {
    const btn = document.getElementById('public-mobile-menu-toggle');
    const menu = document.getElementById('public-mobile-menu');
    if (btn && menu) {
        btn.addEventListener('click', () => menu.classList.toggle('hidden'));
    }
})();
</script>
