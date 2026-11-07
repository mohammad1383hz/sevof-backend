<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReferralRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** بررسی درخواست‌های «کسب درآمد با معرفی» در پنل ادمین. */
class AdminReferralRequestController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->string('status')->toString() ?: null;

        $requests = ReferralRequest::query()
            ->with('user:id,name,email,phone')
            ->when(in_array($status, ReferralRequest::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.referral-requests.index', [
            'requests' => $requests,
            'status' => $status,
            'counts' => ReferralRequest::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
        ]);
    }

    public function update(Request $request, ReferralRequest $referralRequest): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(ReferralRequest::STATUSES)],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $referralRequest->update($data + ['reviewed_at' => now()]);

        return back()->with('success', "Request #{$referralRequest->id} updated.");
    }
}
