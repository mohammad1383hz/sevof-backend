@extends('layouts.admin')

@section('content')
@php
    $isEdit = $announcement->exists;
    $audience = old('audience', $announcement->audience);
    $selectedPlans = old('plan_ids', $isEdit ? $announcement->plans->pluck('id')->all() : []);
    $isActive = $errors->any() ? old('is_active') : $announcement->is_active;
@endphp

<div class="max-w-2xl">

    <h1 class="text-lg font-bold text-slate-800 mb-5">
        {{ $isEdit ? 'ویرایش اطلاعیه' : 'اطلاعیه جدید' }}
    </h1>

    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 text-red-700 text-xs px-4 py-3">
            <ul class="list-disc pr-4 space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
          action="{{ $isEdit ? route('admin.announcements.update', $announcement) : route('admin.announcements.store') }}"
          class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 space-y-4">
        @csrf
        @if($isEdit) @method('PUT') @endif

        <div>
            <label class="block text-xs text-slate-500 mb-1">عنوان</label>
            <input type="text" name="title" value="{{ old('title', $announcement->title) }}" required
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-brand-500">
        </div>

        <div>
            <label class="block text-xs text-slate-500 mb-1">متن اطلاعیه</label>
            <textarea name="body" rows="5" required
                      class="w-full border border-slate-300 rounded-lg px-3 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-brand-500">{{ old('body', $announcement->body) }}</textarea>
        </div>

        <div>
            <label class="block text-xs text-slate-500 mb-1">مخاطب</label>
            <select name="audience" id="audience"
                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                @foreach(\App\Models\Announcement::AUDIENCES as $key => $label)
                    <option value="{{ $key }}" @selected($audience === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div id="plans-box" class="rounded-lg border border-slate-200 p-3 space-y-2 {{ $audience === 'plans' ? '' : 'hidden' }}">
            <div class="text-xs text-slate-500">پلن‌های هدف</div>
            @foreach($plans as $plan)
                <label class="flex items-center gap-2 text-xs text-slate-700">
                    <input type="checkbox" name="plan_ids[]" value="{{ $plan->id }}" @checked(in_array($plan->id, $selectedPlans))>
                    {{ $plan->name }}
                </label>
            @endforeach
        </div>

        <label class="flex items-center gap-2 text-xs text-slate-700">
            <input type="checkbox" name="is_active" value="1" @checked($isActive)>
            فعال باشد
        </label>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit"
                    class="px-5 py-2 rounded-lg text-xs font-medium bg-brand-600 text-white hover:bg-brand-700 transition">
                {{ $isEdit ? 'ذخیره تغییرات' : 'ساخت اطلاعیه' }}
            </button>
            <a href="{{ route('admin.announcements.index') }}" class="text-xs text-slate-500 hover:text-brand-600">انصراف</a>
        </div>
    </form>
</div>

<script>
    document.getElementById('audience').addEventListener('change', function () {
        document.getElementById('plans-box').classList.toggle('hidden', this.value !== 'plans');
    });
</script>
@endsection
