<footer class="bg-[#F3F5F8] text-slate-600 border-t border-slate-200/90 pt-14 pb-8 px-4 sm:px-6">
    <div class="max-w-[1200px] mx-auto">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-10 pb-10">
            <!-- Column 1: Brand Overview + 4 Grey Circular Icons -->
            <div class="space-y-4">
                <a href="{{ route('home') }}" class="inline-flex items-center gap-2.5">
                    <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-[#0284C7] to-[#2563EB] text-white font-black text-sm flex items-center justify-center shadow-xs">A</span>
                    <span class="text-base font-extrabold text-slate-900 tracking-tight">Alom's English Center</span>
                </a>
                <p class="text-xs leading-relaxed text-slate-500">
                    Alom's English Center is a structured online and campus learning platform providing IELTS, Spoken English, and Grammar programs delivered by certified educators nationwide.
                </p>
                <div class="flex items-center gap-2.5 pt-1">
                    <a href="{{ route('public.learn') }}" aria-label="YouTube Lessons" class="w-9 h-9 rounded-full bg-[#E9ECEF] hover:bg-[#0284C7] text-slate-700 hover:text-white flex items-center justify-center transition-colors">
                        <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24" aria-hidden="true"><path d="M21.6 7.2a2.7 2.7 0 0 0-1.9-1.9C18 4.8 12 4.8 12 4.8s-6 0-7.7.5a2.7 2.7 0 0 0-1.9 1.9C2 8.9 2 12 2 12s0 3.1.4 4.8a2.7 2.7 0 0 0 1.9 1.9c1.7.5 7.7.5 7.7.5s6 0 7.7-.5a2.7 2.7 0 0 0 1.9-1.9c.4-1.7.4-4.8.4-4.8s0-3.1-.4-4.8zM10 15.2V8.8L15.4 12 10 15.2z"/></svg>
                    </a>
                    <a href="{{ route('public.learn') }}" aria-label="Free Video Hub" class="w-9 h-9 rounded-full bg-[#E9ECEF] hover:bg-[#0284C7] text-slate-700 hover:text-white flex items-center justify-center transition-colors">
                        <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                    </a>
                    <a href="{{ route('public.instructors') }}" aria-label="Faculty Community" class="w-9 h-9 rounded-full bg-[#E9ECEF] hover:bg-[#0284C7] text-slate-700 hover:text-white flex items-center justify-center transition-colors">
                        <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24" aria-hidden="true"><path d="M13.5 9H16V6h-2.5C11.6 6 10 7.6 10 9.5V11H8v3h2v7h3v-7h2.3l.7-3H13V9.5c0-.3.2-.5.5-.5z"/></svg>
                    </a>
                    <a href="{{ route('public.courses') }}" aria-label="All Courses" class="w-9 h-9 rounded-full bg-[#E9ECEF] hover:bg-[#0284C7] text-slate-700 hover:text-white flex items-center justify-center transition-colors font-bold text-xs">
                        <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24" aria-hidden="true"><path d="M13.5 9H16V6h-2.5C11.6 6 10 7.6 10 9.5V11H8v3h2v7h3v-7h2.3l.7-3H13V9.5c0-.3.2-.5.5-.5z"/></svg>
                    </a>
                </div>
            </div>

            <!-- Column 2: Links -->
            <div>
                <h4 class="text-sm font-bold text-slate-900 mb-4">Links</h4>
                <ul class="space-y-2.5 text-xs text-slate-600">
                    <li><a href="{{ route('home') }}" class="inline-flex items-center gap-2 hover:text-[#0284C7] transition-colors"><span class="text-slate-400">&rarr;</span><span>Home</span></a></li>
                    <li><a href="{{ route('public.courses') }}" class="inline-flex items-center gap-2 hover:text-[#0284C7] transition-colors"><span class="text-slate-400">&rarr;</span><span>Courses</span></a></li>
                    <li><a href="{{ route('public.instructors') }}" class="inline-flex items-center gap-2 hover:text-[#0284C7] transition-colors"><span class="text-slate-400">&rarr;</span><span>Instructors</span></a></li>
                    <li><a href="{{ route('public.ebooks') }}" class="inline-flex items-center gap-2 hover:text-[#0284C7] transition-colors"><span class="text-slate-400">&rarr;</span><span>E-Books</span></a></li>
                    <li><a href="{{ route('public.products') }}" class="inline-flex items-center gap-2 hover:text-[#0284C7] transition-colors"><span class="text-slate-400">&rarr;</span><span>Products</span></a></li>
                    <li><a href="{{ route('public.exams') }}" class="inline-flex items-center gap-2 hover:text-[#0284C7] transition-colors"><span class="text-slate-400">&rarr;</span><span>Exams</span></a></li>
                    <li><a href="{{ route('public.result') }}" class="inline-flex items-center gap-2 hover:text-[#0284C7] transition-colors"><span class="text-slate-400">&rarr;</span><span>Board Result</span></a></li>
                </ul>
            </div>

            <!-- Column 3: Company Details -->
            <div>
                <h4 class="text-sm font-bold text-slate-900 mb-4">Company Details</h4>
                <div class="space-y-3 text-xs text-slate-600">
                    <div class="flex items-start gap-2.5">
                        <svg class="w-4 h-4 text-[#0284C7] shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                        <span>License No: <strong class="font-mono text-slate-800">TRAD/DNCC/044200/2026</strong></span>
                    </div>
                    <div class="flex items-start gap-2.5">
                        <svg class="w-4 h-4 text-[#0284C7] shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                        <span>Plot 14, Sector 6, Mirpur 10 Roundabout, Dhaka-1216</span>
                    </div>
                </div>
            </div>

            <!-- Column 4: Contact -->
            <div>
                <h4 class="text-sm font-bold text-slate-900 mb-4">Contact</h4>
                <div class="space-y-3 text-xs text-slate-600">
                    <div class="flex items-center gap-2.5">
                        <svg class="w-4 h-4 text-[#0284C7] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" /></svg>
                        <a href="tel:+8801712345678" class="font-mono tabular-nums font-semibold text-slate-800 hover:text-[#0284C7]">+880 1712 345678</a>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <svg class="w-4 h-4 text-[#0284C7] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
                        <a href="mailto:info@alomsenglish.com" class="hover:text-[#0284C7]">info@alomsenglish.com</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Centered White Pay With Strip matching Screenshot 1 -->
        <div class="max-w-4xl mx-auto my-4 bg-white border border-slate-200/80 rounded-xl px-5 py-3.5 shadow-2xs flex flex-col sm:flex-row items-center justify-between gap-3">
            <div class="flex flex-wrap items-center justify-center gap-1.5">
                <span class="text-xs font-bold text-[#0284C7] mr-2 whitespace-nowrap">Pay With</span>
                @foreach ([
                    ['VISA', 'text-[#1A1F71] border-indigo-200 bg-indigo-50/70'],
                    ['Mastercard', 'text-[#EB001B] border-red-200 bg-red-50/70'],
                    ['AMEX', 'text-[#006FCF] border-sky-200 bg-sky-50/70'],
                    ['DBBL Nexus', 'text-[#005B9F] border-blue-200 bg-blue-50/70'],
                    ['bKash', 'text-[#E2136E] border-pink-200 bg-pink-50/70'],
                    ['Nagad', 'text-[#F6921E] border-orange-200 bg-orange-50/70'],
                    ['Rocket', 'text-[#8C3494] border-purple-200 bg-purple-50/70'],
                    ['Upay', 'text-[#0066B3] border-sky-200 bg-sky-50/70'],
                    ['City Bank', 'text-[#B91C1C] border-rose-200 bg-rose-50/70'],
                    ['Islami Bank', 'text-[#047857] border-emerald-200 bg-emerald-50/70'],
                    ['UCB', 'text-[#B91C1C] border-red-200 bg-red-50/70'],
                    ['MTB', 'text-[#0369A1] border-sky-200 bg-sky-50/70'],
                    ['BRAC Bank', 'text-[#0F766E] border-teal-200 bg-teal-50/70'],
                    ['EBL', 'text-[#1D4ED8] border-blue-200 bg-blue-50/70'],
                ] as [$gw, $cls])
                    <span class="px-2 py-0.5 rounded border text-[10px] font-bold tracking-tight whitespace-nowrap {{ $cls }}">{{ $gw }}</span>
                @endforeach
            </div>
            <div class="sm:pl-3 sm:border-l border-slate-200 text-center sm:text-right shrink-0">
                <div class="text-[9px] text-slate-400 leading-none">Verified by</div>
                <div class="text-[11px] font-black text-[#1E3A8A] tracking-tight whitespace-nowrap">SSLCOMMERZ</div>
            </div>
        </div>

        <!-- Bottom Copyright & Policy Row -->
        <div class="mt-8 pt-5 border-t border-slate-300/70 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500">
            <div>&copy; {{ date('Y') }} Alom's English Center. All Rights Reserved.</div>
            <div class="flex items-center gap-5">
                <a href="{{ route('public.courses') }}" class="hover:text-slate-900">Terms &amp; Conditions</a>
                <a href="{{ route('public.courses') }}" class="hover:text-slate-900">Privacy Policy</a>
                <a href="{{ route('public.courses') }}" class="hover:text-slate-900">Refund Policy</a>
            </div>
        </div>
    </div>
</footer>
