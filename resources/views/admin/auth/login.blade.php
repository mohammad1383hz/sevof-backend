@extends('layouts.admin')

@section('content')
<div class="min-h-screen flex items-center justify-center bg-gray-50 px-4" dir="rtl">
    <div class="w-full max-w-sm bg-white rounded-xl shadow-sm border border-gray-100 p-8">
        <h1 class="text-2xl font-bold text-gray-800 text-center">ورود به پنل مدیریت</h1>
        <p class="text-sm text-gray-500 text-center mt-1 mb-6">برای مشاهده گزارش‌های سیستم وارد شوید.</p>

        @if($errors->any())
            <div class="bg-red-50 border border-red-100 text-red-600 text-sm rounded-lg px-4 py-2 mb-4">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.login.submit') }}" class="flex flex-col gap-4">
            @csrf

            <div class="flex flex-col">
                <label for="username" class="text-xs text-gray-500 mb-1">نام کاربری</label>
                <input id="username" name="username" value="{{ old('username') }}" required autofocus
                       class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
            </div>

            <div class="flex flex-col">
                <label for="password" class="text-xs text-gray-500 mb-1">رمز عبور</label>
                <input id="password" type="password" name="password" required
                       class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
            </div>

            <button type="submit"
                    class="mt-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-5 py-2.5 rounded-lg transition">
                ورود
            </button>
        </form>
    </div>
</div>
@endsection
