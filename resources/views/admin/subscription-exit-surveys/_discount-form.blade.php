{{-- resources/views/admin/subscription-exit-surveys/_discount-form.blade.php --}}
<details class="group">
    <summary class="list-none cursor-pointer inline-flex items-center gap-1 text-brand-700 hover:text-brand-800 font-medium select-none">
        <svg class="w-3.5 h-3.5 transition-transform group-open:rotate-45" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
        </svg>
        تخصیص تخفیف
    </summary>

    <form method="POST"
          action="{{ route('admin.subscription-exit-surveys.assign-discount', $user) }}"
          class="mt-2 flex flex-wrap items-end gap-2 bg-slate-50 border border-slate-200 rounded-lg p-3">
        @csrf

        <div class="flex flex-col gap-1">
            <label class="text-[10px] text-slate-500">درصد</label>
            <input type="number" name="percentage" min="1" max="100" required
                   placeholder="مثلاً 20"
                   class="w-20 border border-slate-300 rounded-md px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-brand-500">
        </div>

        <div class="flex flex-col gap-1">
            <label class="text-[10px] text-slate-500">مدت (دقیقه)</label>
            <input type="number" name="duration_minutes" min="1" required
                   placeholder="مثلاً 60"
                   class="w-24 border border-slate-300 rounded-md px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-brand-500">
        </div>

        <label class="flex items-center gap-1.5 text-[11px] text-slate-600 pb-1.5">
            <input type="checkbox" name="activate_on_login" value="1"
                   class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
            از لاگین بعدی فعال شود
        </label>

        <button type="submit"
                class="bg-brand-600 hover:bg-brand-700 text-white text-xs font-medium px-3.5 py-1.5 rounded-md transition-colors">
            ثبت تخفیف
        </button>

        @error('percentage')
            <div class="basis-full text-[11px] text-red-600">{{ $message }}</div>
        @enderror
        @error('duration_minutes')
            <div class="basis-full text-[11px] text-red-600">{{ $message }}</div>
        @enderror
    </form>
</details>
