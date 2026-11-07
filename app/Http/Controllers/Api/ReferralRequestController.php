<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReferralRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** «کسب درآمد با معرفی»: کاربر درخواست همکاری ثبت می‌کند و تیم بعد از بررسی با او تماس می‌گیرد. */
class ReferralRequestController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $latest = ReferralRequest::where('user_id', $request->user()->id)->latest('id')->first();

        return response()->json([
            'request' => $latest ? $this->payload($latest) : null,
            'default_phone' => $request->user()->phone,
            'channels' => ReferralRequest::CHANNELS,
            'audience_sizes' => ReferralRequest::AUDIENCE_SIZES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $open = ReferralRequest::where('user_id', $user->id)
            ->whereIn('status', [ReferralRequest::STATUS_PENDING, ReferralRequest::STATUS_APPROVED])
            ->latest('id')
            ->first();

        if ($open) {
            return response()->json([
                'message' => $open->status === ReferralRequest::STATUS_APPROVED
                    ? 'Your partnership request has already been approved.'
                    : 'Your previous request is under review. We\'ll contact you soon.',
                'request' => $this->payload($open),
            ], 409);
        }

        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'channels' => ['required', 'array', 'min:1', 'max:9'],
            'channels.*' => ['string', Rule::in(array_keys(ReferralRequest::CHANNELS))],
            'audience_size' => ['nullable', 'string', Rule::in(array_keys(ReferralRequest::AUDIENCE_SIZES))],
            'description' => ['required', 'string', 'min:20', 'max:2000'],
        ], [
            'channels.required' => 'Choose at least one place where you\'ll promote us.',
            'description.min' => 'Please tell us a bit more (at least 20 characters).',
        ], [
            'phone' => 'Phone number',
            'channels' => 'Promotion channel',
            'description' => 'Description',
        ]);

        $phone = ReferralRequest::normalizePhone($validated['phone']);

        if (! $phone) {
            return response()->json([
                'message' => 'Enter a valid phone number, including country code.',
                'errors' => ['phone' => ['Enter a valid phone number, including country code.']],
            ], 422);
        }

        $referral = ReferralRequest::create([
            'user_id' => $user->id,
            'phone' => $phone,
            'channels' => array_values(array_unique($validated['channels'])),
            'audience_size' => $validated['audience_size'] ?? null,
            'description' => trim(strip_tags($validated['description'])),
            'status' => ReferralRequest::STATUS_PENDING,
        ]);

        return response()->json([
            'message' => 'Your request has been received. We\'ll contact you after review.',
            'request' => $this->payload($referral),
        ], 201);
    }

    protected function payload(ReferralRequest $referral): array
    {
        return [
            'id' => $referral->id,
            'status' => $referral->status,
            'phone' => $referral->phone,
            'channels' => $referral->channels,
            'audience_size' => $referral->audience_size,
            'description' => $referral->description,
            'created_at' => $referral->created_at?->toISOString(),
        ];
    }
}
