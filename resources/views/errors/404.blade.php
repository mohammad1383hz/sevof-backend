<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>یوتیکس — صفحه پیدا نشد</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { vazir: ['Vazirmatn', 'sans-serif'] },
                    colors: {
                        ink: '#1E2A28',
                        moss: '#1B6F63',
                        sand: '#DAD5C7',
                        stone: '#9BA69F',
                    },
                    keyframes: {
                        floaty: {
                            '0%, 100%': { transform: 'translateY(0px)' },
                            '50%': { transform: 'translateY(-10px)' },
                        },
                    },
                    animation: {
                        floaty: 'floaty 4s ease-in-out infinite',
                    },
                },
            },
        };
    </script>
    <style>
        body { font-family: 'Vazirmatn', sans-serif; }
        .grid-fade {
            background-image:
                linear-gradient(to left, rgba(30,42,40,0.05) 1px, transparent 1px),
                linear-gradient(to top, rgba(30,42,40,0.05) 1px, transparent 1px);
            background-size: 36px 36px;
            mask-image: radial-gradient(ellipse at center, black 30%, transparent 75%);
            -webkit-mask-image: radial-gradient(ellipse at center, black 30%, transparent 75%);
        }
    </style>
</head>
<body class="bg-[#F7F5EF] text-ink min-h-screen flex items-center justify-center relative overflow-hidden">

    <div class="absolute inset-0 grid-fade pointer-events-none"></div>

    <main class="relative z-10 w-full max-w-lg mx-auto px-6 text-center">

        <div class="flex justify-center mb-6">
            <span class="inline-flex items-center gap-2 rounded-full border border-sand bg-white px-4 py-1.5 text-sm font-medium text-moss">
                <span class="relative flex h-2 w-2">
                    <span class="absolute inline-flex h-full w-full rounded-full bg-moss opacity-60 animate-ping"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-moss"></span>
                </span>
                یوتیکس
            </span>
        </div>

        <div class="relative mb-4 select-none animate-floaty">
            <p class="text-[120px] sm:text-[160px] font-extrabold leading-none tracking-tight text-ink/90">
                404
            </p>
            <svg class="absolute -top-3 -left-2 sm:-left-6 h-10 w-10 sm:h-14 sm:w-14 text-moss/70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                <path d="M9.17 14.83a4 4 0 0 1 0-5.66l3-3a4 4 0 0 1 5.66 5.66l-1.5 1.5" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M14.83 9.17a4 4 0 0 1 0 5.66l-3 3a4 4 0 0 1-5.66-5.66l1.5-1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>

        <h1 class="text-xl sm:text-2xl font-bold text-ink mb-2">
            این صفحه توی یوتیکس پیدا نشد
        </h1>
        <p class="text-sm sm:text-base text-stone leading-relaxed mb-8">
            آدرسی که دنبالش بودی یا جابه‌جا شده یا اصلاً وجود نداشته.
            می‌تونی از آدرس درست دوباره امتحان کنی یا برگردی به صفحه‌ی اصلی.
        </p>

        <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
            <a href="https://app.youtix.site"
               class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-ink text-white text-sm font-medium px-6 py-3 hover:bg-moss transition-colors">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path d="M3 12l9-9 9 9M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                برگشت به صفحه اصلی
            </a>
            <a href="javascript:history.back()"
               class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl border border-sand bg-white text-sm font-medium text-ink px-6 py-3 hover:border-moss hover:text-moss transition-colors">
                برگرد به صفحه قبل
            </a>
        </div>

        <p class="mt-10 text-xs text-stone">
            کد خطا: 404 — صفحه یا منبع درخواستی در سرورهای یوتیکس موجود نیست.
        </p>
    </main>

</body>
</html>
