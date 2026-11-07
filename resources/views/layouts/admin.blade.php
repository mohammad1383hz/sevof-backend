<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'پنل مدیریت' }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="bg-slate-100 text-slate-800 font-vazir text-sm">

@if(isset($currentAdmin))
    <header class="bg-slate-900 text-white px-4 sm:px-6 py-3 flex items-center justify-between gap-4 sticky top-0 z-40 shadow-md">
        <div class="flex items-center gap-3 min-w-0">
            {{-- دکمه همبرگر - فقط موبایل --}}
            <button type="button" onclick="toggleSidebar()"
                    class="md:hidden shrink-0 w-9 h-9 flex items-center justify-center rounded-lg hover:bg-slate-800 transition-colors">
                <svg id="menu-icon-open" xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
                <svg id="menu-icon-close" xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>

            <div class="w-9 h-9 rounded-lg bg-brand-600 flex items-center justify-center font-bold shrink-0">پ</div>
            <div class="min-w-0">
                <strong class="block text-[15px] leading-tight truncate">پنل مدیریت سیستم</strong>
                <small class="text-slate-400 truncate block">{{ $currentAdmin->name }} · {{ $currentAdmin->role }}</small>
            </div>
        </div>
        <form method="POST" action="{{ route('admin.logout') }}" class="m-0 shrink-0">
            @csrf
            <button type="submit" class="border border-slate-600 hover:bg-slate-800 transition-colors text-white px-3.5 py-1.5 rounded-lg text-xs whitespace-nowrap">
                خروج
            </button>
        </form>
    </header>

    <div class="flex min-h-[calc(100vh-57px)] relative">
        {{-- overlay پس‌زمینه موبایل --}}
        <div id="sidebar-overlay" onclick="toggleSidebar()"
             class="hidden fixed inset-0 bg-black/40 z-20 md:hidden"></div>

        <aside id="sidebar"
               class="fixed md:sticky top-[57px] md:top-0 right-0 h-[calc(100vh-57px)] md:h-auto
                      w-64 md:w-56 shrink-0 bg-slate-800 p-3
                      translate-x-full md:translate-x-0 transition-transform duration-300 ease-in-out
                      z-30 overflow-y-auto">
            @php
                $navItems = [
                    ['route' => 'admin.dashboard', 'label' => 'داشبورد', 'is' => 'admin.dashboard'],
                    ['route' => 'admin.sales.index', 'label' => 'گزارش فروش', 'is' => 'admin.sales.*'],
                    ['route' => 'admin.users.index', 'label' => 'کاربران', 'is' => 'admin.users.*'],
                    ['route' => 'admin.activities.index', 'label' => 'فعالیت‌ها', 'is' => 'admin.activities.*'],
                    ['route' => 'admin.behavior.index', 'label' => 'تحلیل رفتار و Funnel', 'is' => 'admin.behavior.*'],
                    ['route' => 'admin.page-visits.index', 'label' => 'بازدید ها', 'is' => 'admin.page-visits.*'],
                    ['route' => 'admin.tickets.index', 'label' => 'تیکت‌ها', 'is' => 'admin.tickets.*'],
                            ['route' => 'admin.announcements.index', 'label' => 'اطلاعیه‌ها', 'is' => 'admin.announcements.*'], // ← جدید

                    ['route' => 'admin.usage.index', 'label' => 'مصرف ها', 'is' => 'admin.usage.*'],
                    ['route' => 'admin.subscription-exit-surveys.index', 'label' => 'نظرسنجی خروج از اشتراک', 'is' => 'admin.subscription-exit-surveys.*'],
                    ['route' => 'admin.referral-requests.index', 'label' => 'درخواست‌های معرفی', 'is' => 'admin.referral-requests.*'],
                    ['route' => 'admin.indexing.index', 'label' => 'وضعیت ایندکس مقاله‌ها', 'is' => 'admin.indexing.*'],
                    ['route' => 'admin.content-audit.index', 'label' => 'گزارش سئوی مقاله‌های گذشته', 'is' => 'admin.content-audit.*'],
                ];
            @endphp
            @foreach($navItems as $item)
                <a href="{{ route($item['route']) }}"
                   class="block whitespace-nowrap px-3.5 py-2.5 rounded-lg mb-1 text-slate-300 text-[13px] transition-colors
                          hover:bg-slate-700 hover:text-white
                          {{ request()->routeIs($item['is']) ? 'bg-brand-600 text-white font-medium' : '' }}">
                    {{ $item['label'] }}
                </a>
            @endforeach
        </aside>

        <main class="flex-1 p-4 sm:p-6 overflow-auto w-full min-w-0">
            @yield('content')
        </main>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            const openIcon = document.getElementById('menu-icon-open');
            const closeIcon = document.getElementById('menu-icon-close');

            const isOpen = !sidebar.classList.contains('translate-x-full');

            if (isOpen) {
                sidebar.classList.add('translate-x-full');
                overlay.classList.add('hidden');
                openIcon.classList.remove('hidden');
                closeIcon.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
            } else {
                sidebar.classList.remove('translate-x-full');
                overlay.classList.remove('hidden');
                openIcon.classList.add('hidden');
                closeIcon.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
            }
        }

        // بستن منو با تغییر سایز به دسکتاپ
        window.addEventListener('resize', function () {
            if (window.innerWidth >= 768) {
                document.getElementById('sidebar').classList.remove('translate-x-full');
                document.getElementById('sidebar-overlay').classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
            } else {
                document.getElementById('sidebar').classList.add('translate-x-full');
            }
        });
    </script>
@else
    @yield('content')
@endif

@stack('scripts')
</body>
</html>
