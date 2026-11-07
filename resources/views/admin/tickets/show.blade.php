@extends('layouts.admin')

@section('content')
@php
    $ticketUser = $ticket->user;
    $isClosed   = $ticket->status === 'closed';
    $initial    = $ticketUser ? mb_substr(trim($ticketUser->name ?? '?'), 0, 1) : '?';
@endphp

{{-- ===================== هدر تیکت ===================== --}}
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 sm:p-5 mb-6">
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">

        {{-- اطلاعات تیکت و کاربر --}}
        <div class="flex items-start gap-3 min-w-0">
            <div class="shrink-0 w-11 h-11 sm:w-12 sm:h-12 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-lg font-bold">
                {{ $initial }}
            </div>

            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-lg sm:text-xl font-bold text-gray-800 break-words">{{ $ticket->subject }}</h1>
                    <span class="text-[11px] font-medium px-2 py-0.5 rounded-full border
                        {{ $isClosed ? 'bg-gray-100 text-gray-500 border-gray-200' : 'bg-green-50 text-green-600 border-green-100' }}">
                        {{ $isClosed ? 'بسته شده' : 'باز' }}
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1.5 text-xs sm:text-sm text-gray-500">
                    <code class="text-xs bg-gray-100 text-gray-600 rounded px-1.5 py-0.5">تیکت #{{ $ticket->id }}</code>
                    <span class="break-words">{{ $ticketUser->name ?? '—' }}</span>
                    <span class="break-words" dir="ltr">{{ $ticketUser->email ?? '' }}</span>
                </div>
            </div>
        </div>

        {{-- دکمه‌ها --}}
        <div class="flex flex-col sm:flex-row gap-2 shrink-0">
            @if($ticketUser)
                <form action="{{ route('admin.users.impersonate', $ticketUser->id) }}" method="POST" target="_blank"
                      onsubmit="return confirm('وارد حساب کاربر #{{ $ticketUser->id }} ({{ e($ticketUser->name) }}) بشی؟');">
                    @csrf
                    <button type="submit"
                            class="w-full sm:w-auto inline-flex items-center justify-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium border border-emerald-200 text-emerald-600 bg-emerald-50 hover:bg-emerald-100 transition whitespace-nowrap">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                            <polyline points="10 17 15 12 10 7"/>
                            <line x1="15" y1="12" x2="3" y2="12"/>
                        </svg>
                        ورود به حساب کاربر
                    </button>
                </form>

                <a href="{{ route('admin.users.show', $ticketUser->id) }}"
                   class="w-full sm:w-auto inline-flex items-center justify-center px-4 py-2 rounded-lg text-sm font-medium border border-gray-200 text-gray-600 hover:bg-gray-50 transition whitespace-nowrap">
                    پروفایل کاربر
                </a>
            @endif

            @if(!$isClosed)
                <form method="POST" action="{{ route('admin.tickets.close', $ticket) }}"
                      onsubmit="return confirm('این تیکت بسته بشه؟');">
                    @csrf
                    <button type="submit"
                            class="w-full sm:w-auto px-4 py-2 rounded-lg bg-red-50 hover:bg-red-100 text-red-600 text-sm font-medium border border-red-100 transition whitespace-nowrap">
                        بستن تیکت
                    </button>
                </form>
            @endif
        </div>
    </div>
</div>

@if(session('success'))
    <div class="mb-4 rounded-lg bg-green-50 border border-green-100 text-green-700 text-sm px-4 py-3">{{ session('success') }}</div>
@endif

{{-- ===================== گفتگو ===================== --}}
<section class="bg-white rounded-xl shadow-sm border border-gray-100 p-3 sm:p-5">
    <div class="flex flex-col gap-4">
        @foreach($ticket->replies as $reply)
            <div class="rounded-xl border p-3 sm:p-4 {{ $reply->is_staff_reply ? 'bg-indigo-50 border-indigo-100' : 'bg-gray-50 border-gray-100' }}">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 mb-2">
                    <strong class="text-sm text-gray-800">{{ $reply->is_staff_reply ? ($reply->admin->name ?? 'پشتیبانی') : ($reply->user->name ?? 'کاربر') }}</strong>
                    <small class="text-xs text-gray-400">{{ $reply->created_at->format('Y-m-d H:i:s') }}</small>
                </div>
                <div class="text-sm text-gray-700 whitespace-pre-line break-words">{{ $reply->message }}</div>

                @if($reply->attachments->isNotEmpty())
                    <div class="grid grid-cols-3 sm:flex sm:flex-wrap gap-2 mt-3">
                        @foreach($reply->attachments as $attachment)
                            <a href="{{ $attachment->url }}" target="_blank" rel="noopener" class="block">
                                <img src="{{ $attachment->url }}"
                                     alt="{{ $attachment->original_name }}"
                                     class="w-full h-24 sm:w-[150px] sm:h-[150px] rounded-lg object-cover border border-gray-200 hover:opacity-90 transition">
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    @if(!$isClosed)
    <form method="POST" action="{{ route('admin.tickets.reply', $ticket) }}" enctype="multipart/form-data" class="mt-6 border-t border-gray-100 pt-5">
        @csrf
        <textarea name="message" rows="4" placeholder="پاسخ خود را بنویسید..." required
                  class="w-full rounded-lg border-gray-300 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"></textarea>
        @error('message')<small class="text-red-500 text-xs mt-1 block">{{ $message }}</small>@enderror

        <div class="mt-3">
            <label class="text-xs font-medium text-gray-500">ضمیمه کردن تصویر (اختیاری - حداکثر ۵ عکس)</label>
            <input type="file" name="images[]" accept="image/*" multiple
                   class="mt-1 block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-indigo-50 file:text-indigo-600 hover:file:bg-indigo-100">
            @error('images')<small class="text-red-500 text-xs mt-1 block">{{ $message }}</small>@enderror
            @error('images.*')<small class="text-red-500 text-xs mt-1 block">{{ $message }}</small>@enderror
        </div>

        <button type="submit" class="mt-4 w-full sm:w-auto px-5 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium transition">ارسال پاسخ</button>
    </form>
    @else
    <p class="text-sm text-gray-400 mt-6 border-t border-gray-100 pt-5">این تیکت بسته شده است.</p>
    @endif
</section>
@endsection
