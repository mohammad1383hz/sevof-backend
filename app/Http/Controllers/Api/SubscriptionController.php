<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\Billing\StripeService;
use App\Services\DiscountService;
use App\Services\SubscriptionService;
use App\Services\TokenUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubscriptionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $plans = Plan::active()->orderBy('price')->get();

        return PlanResource::collection($plans);
    }

    public function show(Plan $plan): PlanResource
    {
        return new PlanResource($plan);
    }

    public function me(Request $request, SubscriptionService $subscriptionService): JsonResponse
    {
        $stats = $subscriptionService->getUsageStats($request->user());

        return response()->json(['subscription' => $stats]);
    }

    public function usageHistory(Request $request, TokenUsageService $tokenUsageService): JsonResponse
    {
        $history = $tokenUsageService->getUserUsageHistory($request->user());

        return response()->json(['usage' => $history]);
    }

    /**
     * Step 1: the user picks a plan, a period and optionally a discount code.
     * Free (or 100%-discounted) plans activate immediately; paid ones create a pending
     * Payment and a Stripe Checkout Session. Activation/queueing after payment happens in
     * StripeService::fulfill → SubscriptionService::purchaseSubscription:
     *  - active paid plan → new plan is queued
     *  - active free plan → free is cancelled, new plan starts now
     *  - nothing active    → new plan starts now
     * A user may hold at most one queued plan.
     */
    public function subscribe(
        Request $request,
        Plan $plan,
        SubscriptionService $subscriptionService,
        DiscountService $discountService,
        StripeService $stripe,
    ): JsonResponse {
        $user = $request->user();

        $data = $request->validate([
            'discount_code' => 'nullable|string|size:5',
            'period_months' => 'nullable|integer|in:'.implode(',', Plan::PERIODS),
        ]);

        // Purchase length (1/3/6 months); the free plan is always monthly.
        $periodMonths = $subscriptionService->normalizePeriod($plan, (int) ($data['period_months'] ?? 1));

        if ($subscriptionService->hasQueuedSubscription($user)) {
            return response()->json([
                'message' => 'You already have a next plan queued; it starts automatically when your current plan ends. You can buy another plan after that.',
            ], 422);
        }

        $finalPrice = $plan->priceFor($periodMonths);
        $discount = null;

        if (! empty($data['discount_code'])) {
            $discount = $discountService->findUsableDiscountByCode($data['discount_code'], $user);

            if (! $discount) {
                return response()->json(['message' => 'This discount code is invalid or has expired.'], 422);
            }

            $priceWithCode = $discountService->priceWithDiscount($plan, $discount, $periodMonths);

            // Keep the better price; drop the code if it doesn't help so it isn't marked as used.
            if ($priceWithCode < $finalPrice) {
                $finalPrice = $priceWithCode;
            } else {
                $discount = null;
            }
        }

        if ($finalPrice === 0 && $subscriptionService->hasActivePaidPlan($user)) {
            return response()->json([
                'message' => "You have an active paid plan, so you can't switch to the free plan manually. You'll move to it automatically when your current plan ends.",
            ], 422);
        }

        if ($finalPrice === 0) {
            if ($plan->is_free && $subscriptionService->hasEverHadFreePlan($user)) {
                return response()->json([
                    'message' => 'The free plan can only be used once per account. Please choose a paid plan to continue.',
                ], 422);
            }

            $subscription = $subscriptionService->createSubscription($user, $plan, periodMonths: $periodMonths);

            if ($discount) {
                $discountService->markPersonalDiscountUsed($discount);
            }

            return response()->json([
                'message' => 'Your plan is active.',
                'subscription' => $subscription->load('plan'),
            ]);
        }

        $payment = Payment::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'period_months' => $periodMonths,
            'discount_id' => $discount?->id,
            'amount' => $finalPrice, // minor units (cents), after discounts
            'status' => 'pending',
        ]);

        try {
            $session = $stripe->createCheckoutSession(
                $payment,
                sprintf('%s plan — %d month%s', $plan->name, $periodMonths, $periodMonths > 1 ? 's' : ''),
                $user->email,
            );
        } catch (\Throwable $e) {
            $payment->update(['status' => 'failed']);

            return response()->json(['message' => $e->getMessage() ?: 'Could not connect to the payment provider.'], 422);
        }

        $payment->update(['authority' => $session['id']]);

        return response()->json([
            'message' => 'Redirecting to secure checkout…',
            'payment_url' => $session['url'],
        ]);
    }

    /**
     * Step 2a: the front lands on /payment/result?session_id=… and asks us to confirm.
     * Only the payment's owner may confirm it.
     */
    public function confirmStripe(Request $request, StripeService $stripe): JsonResponse
    {
        $data = $request->validate(['session_id' => ['required', 'string', 'max:255']]);

        $payment = Payment::where('authority', $data['session_id'])->first();

        if (! $payment || $payment->user_id !== $request->user()->id) {
            return response()->json(['status' => 'failed', 'message' => 'Payment not found.'], 404);
        }

        try {
            $status = $stripe->fulfill($stripe->retrieveSession($data['session_id']));
        } catch (\Throwable $e) {
            return response()->json(['status' => 'pending', 'message' => $e->getMessage()], 502);
        }

        return response()->json([
            'status' => $status,
            'ref_id' => $payment->fresh()->ref_id,
            'message' => match ($status) {
                'success' => 'Payment received. Your plan is active.',
                'pending' => 'We are still waiting for Stripe to confirm the payment.',
                default => 'The payment was not completed.',
            },
        ]);
    }

    /**
     * Step 2b: Stripe webhook (checkout.session.completed / async_payment_* / expired).
     */
    public function stripeWebhook(Request $request, StripeService $stripe): JsonResponse
    {
        $payload = $request->getContent();

        if (! $stripe->verifyWebhook($payload, $request->header('Stripe-Signature'))) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $event = json_decode($payload, true) ?: [];
        $type = (string) ($event['type'] ?? '');

        if (in_array($type, [
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
            'checkout.session.async_payment_failed',
            'checkout.session.expired',
        ], true)) {
            $session = (array) ($event['data']['object'] ?? []);

            if ($type === 'checkout.session.async_payment_failed') {
                // terminal failure: same handling as an expired session
                $session['status'] = 'expired';
                $session['payment_status'] = 'unpaid';
            }

            $stripe->fulfill($session);
        }

        return response()->json(['received' => true]);
    }
}
