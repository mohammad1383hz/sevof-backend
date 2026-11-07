{{-- مسیر مقصد: resources/views/errors/layout.blade.php --}}
{{--
    این فایل برای رفع ارور زیر ساخته شده:
    "View [errors.layout] not found. (View: .../resources/views/errors/500.blade.php)"

    یعنی فایل errors/500.blade.php شما با @extends('errors.layout') به این فایل
    نیاز داره ولی وجود نداشت. این یک layout ساده و مستقل (بدون وابستگی به
    layouts.admin، چون در لحظه‌ی بروز ارور 500 ممکنه خود لایوت اصلی هم مشکل داشته باشد)
    با Tailwind (از CDN) ساخته شده.

    نکته مهم: اگر resources/views/errors/500.blade.php شما از section هایی
    غیر از content/title (مثلاً code یا message) استفاده می‌کند، محتوای آن
    فایل را برایم بفرست تا این layout را دقیقاً هماهنگ با آن تنظیم کنم.
--}}
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'خطایی رخ داده است')</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4 font-sans">

    <div class="max-w-md w-full text-center bg-white border border-slate-200 rounded-xl shadow-sm p-8">

        <div class="mx-auto mb-4 w-14 h-14 rounded-full bg-red-100 flex items-center justify-center">
            <svg class="w-7 h-7 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
            </svg>
        </div>

        @hasSection('code')
            <div class="text-4xl font-bold text-slate-800 mb-1">@yield('code')</div>
        @endif

        <h1 class="text-lg font-semibold text-slate-800 mb-2">
            @yield('title', 'خطایی رخ داده است')
        </h1>

        <div class="text-slate-500 text-sm leading-6">
            @yield('content', 'مشکلی در سرور رخ داده. لطفاً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.')
        </div>

        <a href="{{ url('/') }}"
           class="inline-block mt-6 bg-brand-600 hover:bg-brand-700 transition-colors text-white font-medium rounded-lg px-5 py-2 text-sm shadow-sm">
            بازگشت به صفحه اصلی
        </a>
    </div>

</body>
</html>
