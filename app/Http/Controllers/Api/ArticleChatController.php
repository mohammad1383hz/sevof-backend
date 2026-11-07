<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Site;
use App\Services\AI\ArticleActionExecutor;
use App\Services\AI\ArticlePlannerService;
use App\Services\AI\ArticleWriterService;
use App\Services\AI\ChatModelResolver;
use App\Services\ArticleTargetResolver;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;


class ArticleChatController extends Controller
{
    public function __construct(
        private readonly ArticleTargetResolver $resolver,
    ) {}
    protected function authorizeSiteAccess(Request $request, Site $site): void
    {
        abort_if($site->user_id !== $request->user()->id, 403, 'Forbidden.');
    }
    /** تاریخچه‌ی کامل چت مقاله (برای وقتی صفحه‌ی مقاله دوباره باز می‌شود) */
    public function index(Request $request, Site $site, Article $article): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_if($article->website_id !== $site->id, 403, 'Forbidden.');

        $messages = $article->chatMessages()->orderBy('created_at')->get();

        return response()->json(['data' => $messages]);
    }

    /** ارسال یک پیام جدید چت + پردازش sync توسط AI + اعمال تغییر (در صورت نیاز) */
public function store(
    Request $request,
    Site $site,
    Article $article,
    ArticleWriterService $writer,
    ArticlePlannerService $planner,
    ArticleActionExecutor $executor,
): JsonResponse {
    $this->authorizeSiteAccess($request, $site);
    abort_if($article->website_id !== $site->id, 403, 'Forbidden.');

  $subscriptionService = app(SubscriptionService::class);

if (! $subscriptionService->hasAvailableCredit($request->user())) {
    return response()->json([
        'message' => 'You don\'t have an active plan or enough credit. Please renew your plan.',
    ], 402);
}
    app()->bind('current_ai_user', fn () => $request->user());

    $data = $request->validate([
        'target_type' => ['required', Rule::in(ArticleTargetResolver::TYPES)],
        // برای section توکن بخش، برای faq ایندکس سؤال
        'target_id' => ['nullable', 'alpha_num', 'max:40'],
        'message' => ['required', 'string', 'max:4000'],
        'model' => ['nullable', 'string'],
    ]);

    $targetKey = null;
    if (isset($data['target_id'])) {
        $targetKey = $data['target_type'] === 'section'
            ? $article->sections()->where('token', (string) $data['target_id'])->value('id')
            : (ctype_digit((string) $data['target_id']) ? (int) $data['target_id'] : null);
    }

    // تعیین مدل واقعی AI بر اساس مدل درخواستی از فرانت + پلن کاربر.
    // این تنها منبع معتبر برای انتخاب مدل است؛ حتی اگر فرانت دستکاری شود
    // و model_id غیرمجاز/proOnly بدون داشتن پلن Pro بفرستد، اینجا کنترل می‌شود.
    $resolvedModel = ChatModelResolver::resolve(
        $data['model'] ?? null,
        $subscriptionService->hasActivePaidPlan($request->user()), // اگر متد واقعی اسم دیگری دارد، همینجا جایگزین کن
    );

    // اگر کاربر کل مقاله را انتخاب کرده باشد target_id لازم نیست
    if (
        $data['target_type'] !== 'general'
        && in_array($data['target_type'], ArticleTargetResolver::TYPES_REQUIRING_ID, true)
        && ! isset($data['target_id'])
    ) {
        return response()->json(['message' => 'target_id is required for this section type.'], 422);
    }

    // ثبت پیام کاربر (مشترک بین هر دو مسیر)
    $userMessage = $article->chatMessages()->create([
        'role' => 'user',
        'target_type' => $data['target_type'],
        'target_id' => $targetKey,
        'target_label' => $data['target_type'] === 'general' ? 'Whole article' : null, // پایین‌تر برای section اصلاح می‌شود
        'content' => $data['message'],
    ]);

    $history = $article->chatMessages()
        ->where('id', '!=', $userMessage->id)
        ->orderByDesc('created_at')
        ->limit(20)
        ->get(['role', 'content'])
        ->reverse()
        ->values()
        ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
        ->all();

    // ===== مسیر ۲: کل مقاله انتخاب شده — Planner + Executor =====
    if ($data['target_type'] === 'general') {

        set_time_limit(300);

        try {
            $plan = $planner->plan($article, $history, $data['message']);
            $execResults = $executor->execute($article, $plan['actions'], $resolvedModel);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Processing your message failed. Please try again.',
            ], 500);
        }

        $applied = collect($execResults)->contains('ok', true);

        $assistantMessage = $article->chatMessages()->create([
            'role' => 'assistant',
            'target_type' => 'general',
            'target_id' => null,
            'target_label' => 'Whole article',
            'content' => $plan['reply'],
            'applied' => $applied,
        ]);

        return response()->json([
            'user_message' => $userMessage,
            'assistant_message' => $assistantMessage,
            'applied' => $applied,
            'actions' => $execResults,
            'article' => $article->fresh(['sections']),
        ], 201);
    }

    // ===== مسیر ۱: section/faq خاص — دقیقاً همان مسیر قبلی، بدون تغییر =====
    try {
        $target = $this->resolver->resolve($article, $data['target_type'], $targetKey);
    } catch (InvalidArgumentException $e) {
        return response()->json(['message' => $e->getMessage()], 422);
    }

    $userMessage->update(['target_label' => $target['label']]);

    try {
        $result = $writer->chatEdit(
            article: $article,
            targetType: $data['target_type'],
            targetLabel: (string) ($target['label'] ?? ''),
            currentValue: $target['value'],
            history: $history,
            userMessage: $data['message'],
            model: $resolvedModel,
        );
    } catch (Throwable $e) {
        report($e);

        return response()->json(['message' => 'Processing your message failed. Please try again.'], 500);
    }

    $applied = false;
    $updatedTarget = $target;

    if (! empty($result['updated_content'])) {
        $newValue = $result['updated_content'];

        if ($data['target_type'] === 'faq' && is_string($newValue)) {
            $decoded = json_decode($newValue, true);
            $newValue = is_array($decoded)
                ? $decoded
                : ['question' => $target['value']['question'] ?? null, 'answer' => $newValue];
        }

        try {
            $updatedTarget = $this->resolver->apply(
                $article,
                $data['target_type'],
                $targetKey,
                $newValue,
            );
            $applied = true;
        } catch (InvalidArgumentException $e) {
            report($e);
        }
    }

    $assistantMessage = $article->chatMessages()->create([
        'role' => 'assistant',
        'target_type' => $data['target_type'],
        'target_id' => $targetKey,
        'target_label' => $updatedTarget['label'],
        'content' => $result['reply'],
        'applied' => $applied,
    ]);

    return response()->json([
        'user_message' => $userMessage,
        'assistant_message' => $assistantMessage,
        'applied' => $applied,
        'target' => [
            'type' => $data['target_type'],
            'id' => $data['target_id'] ?? null,
            'label' => $updatedTarget['label'],
            'value' => $updatedTarget['value'],
        ],
    ], 201);
}
}
