<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleIdea;
use App\Models\ContentChatMessage;
use App\Models\ContentChatSession;
use App\Models\ContentResearchSession;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContentChatSessionController extends Controller
{
    public function index(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $sessions = $site->contentChatSessions()
            ->where('user_id', $request->user()->id)
            ->with('site:id,token')
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'sessions' => $sessions->map(fn (ContentChatSession $session) => $this->sessionPayload($session)),
        ]);
    }

   public function store(Request $request, Site $site): JsonResponse
{
    $this->authorizeSiteAccess($request, $site);

    $validated = $request->validate([
        'kind' => ['sometimes', Rule::in([ContentChatSession::KIND_ANALYSIS, ContentChatSession::KIND_PRODUCT])],
        'title' => ['sometimes', 'nullable', 'string', 'max:200'],
    ]);
    $kind = $validated['kind'] ?? ContentChatSession::KIND_ANALYSIS;

    if ($kind === ContentChatSession::KIND_PRODUCT) {
        $session = ContentChatSession::create([
            'site_id' => $site->id,
            'user_id' => $request->user()->id,
            'kind' => $kind,
            'title' => $validated['title'] ?? 'Product SEO',
        ]);

        return response()->json(['session' => $this->sessionPayload($session->load('messages'))], 201);
    }

    $session = ContentChatSession::firstOrCreate(
        ['site_id' => $site->id, 'user_id' => $request->user()->id, 'kind' => $kind],
        ['title' => 'Site analysis'],
    );

    return response()->json([
        'session' => $this->sessionPayload($session->load('messages')),
    ], $session->wasRecentlyCreated ? 201 : 200);
}

    public function storeFirstMessage(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $validated = $this->resolveRelatedRecords($site, $request->validate($this->messageRules()));

        [$session, $message] = DB::transaction(function () use ($request, $site, $validated): array {
            $session = ContentChatSession::create([
                'site_id' => $site->id,
                'user_id' => $request->user()->id,
                'kind' => ContentChatSession::KIND_CHAT,
                'title' => $this->titleFromMessage($validated['content']),
            ]);
            $message = $this->createMessage($session, $validated);
            $this->touchSession($session, $message);

            return [$session, $message];
        });

        return response()->json([
            'message' => $this->messagePayload($message),
            'session' => $this->sessionPayload($session->fresh()),
        ], 201);
    }

    public function show(Request $request, Site $site, ContentChatSession $session): JsonResponse
    {
        $this->authorizeSessionAccess($request, $site, $session);

        return response()->json([
            'session' => $this->sessionPayload($session->load([
                'messages' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
            ])),
        ]);
    }

    public function storeMessage(Request $request, Site $site, ContentChatSession $session): JsonResponse
    {
        $this->authorizeSessionAccess($request, $site, $session);
        $validated = $this->resolveRelatedRecords($site, $request->validate($this->messageRules()));

        $message = DB::transaction(function () use ($session, $validated): ContentChatMessage {
            $message = $this->createMessage($session, $validated);
            $this->touchSession($session, $message);
            return $message;
        });

        return response()->json([
            'message' => $this->messagePayload($message),
            'session' => $this->sessionPayload($session->fresh()),
        ], 201);
    }

    public function updateMessage(Request $request, Site $site, ContentChatSession $session, ContentChatMessage $message): JsonResponse
    {
        $this->authorizeSessionAccess($request, $site, $session);
        abort_if($message->content_chat_session_id !== $session->id, 404, 'Message not found.');

        $validated = $request->validate([
            'answered' => ['sometimes', 'boolean'],
            'batch' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'article_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'content_research_session_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);

        $message->update($this->resolveRelatedRecords($site, $validated));

        return response()->json(['message' => $this->messagePayload($message->fresh())]);
    }

    protected function messageRules(): array
    {
        return [
            'role' => ['required', Rule::in(['user', 'assistant'])],
            'kind' => ['required', 'string', 'max:50'],
            'content' => ['required', 'string', 'max:20000'],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'entity' => ['sometimes', 'nullable', 'array'],
            'options' => ['sometimes', 'nullable', 'array'],
            'answered' => ['sometimes', 'boolean'],
            'batch' => ['sometimes', 'nullable', 'integer', 'min:1'],
            // توکن مقاله / تحقیق محتوا؛ resolveRelatedRecords به آی‌دی همین سایت تبدیلشان می‌کند
            'article_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'content_research_session_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }

    protected function createMessage(ContentChatSession $session, array $validated): ContentChatMessage
    {
        return $session->messages()->create([
            'role' => $validated['role'],
            'kind' => $validated['kind'],
            'content' => $validated['content'],
            'label' => $validated['label'] ?? null,
            'entity' => $validated['entity'] ?? null,
            'options' => $validated['options'] ?? null,
            'answered' => $validated['answered'] ?? false,
            'batch' => $validated['batch'] ?? null,
            'article_id' => $validated['article_id'] ?? null,
            'content_research_session_id' => $validated['content_research_session_id'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
        ]);
    }

    protected function touchSession(ContentChatSession $session, ContentChatMessage $message): void
    {
        $session->last_message_at = $message->created_at ?? now();
        if ($session->kind === ContentChatSession::KIND_CHAT && $session->title === 'New conversation' && $message->role === 'user') {
            $session->title = $this->titleFromMessage($message->content);
        }
        $session->save();
    }

    protected function authorizeSiteAccess(Request $request, Site $site): void
    {
        abort_if($site->user_id !== $request->user()->id, 403, 'Forbidden.');
    }

    protected function authorizeSessionAccess(Request $request, Site $site, ContentChatSession $session): void
    {
        $this->authorizeSiteAccess($request, $site);
        abort_if($session->site_id !== $site->id || $session->user_id !== $request->user()->id, 404, 'Conversation not found.');
    }

    /** توکن‌های ورودی باید مال همین سایت باشند و به آی‌دی عددی تبدیل می‌شوند؛ idea_id داخل metadata توکن می‌ماند. */
    protected function resolveRelatedRecords(Site $site, array $validated): array
    {
        if (isset($validated['metadata']['idea_id']) && $validated['metadata']['idea_id'] !== null) {
            $ideaBelongsToSite = ArticleIdea::query()
                ->where('token', (string) $validated['metadata']['idea_id'])
                ->where('website_id', $site->id)
                ->exists();

            abort_unless($ideaBelongsToSite, 422, 'This idea doesn\'t belong to this site.');
        }

        if (isset($validated['article_id'])) {
            $validated['article_id'] = Article::query()
                ->where('token', $validated['article_id'])
                ->where('website_id', $site->id)
                ->value('id');
            abort_unless($validated['article_id'], 422, 'This article doesn\'t belong to this site.');
        }

        if (isset($validated['content_research_session_id'])) {
            $validated['content_research_session_id'] = ContentResearchSession::query()
                ->where('token', $validated['content_research_session_id'])
                ->where('site_id', $site->id)
                ->where('user_id', $site->user_id)
                ->value('id');
            abort_unless($validated['content_research_session_id'], 422, 'This content research session doesn\'t belong to this site.');
        }

        return $validated;
    }

    protected function titleFromMessage(string $content): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $content) ?: 'New conversation');
        return mb_substr($title, 0, 70);
    }

    protected function sessionPayload(ContentChatSession $session): array
    {
        if ($session->relationLoaded('messages')) {
            $session->messages->loadMissing(['session', 'article:id,token', 'researchSession:id,token']);
        }

        return [
            'id' => $session->token,
            'token' => $session->token,
            'site_id' => $session->site?->token,
            'user_id' => $session->user_id,
            'kind' => $session->kind,
            'title' => $session->title,
            'messages_count' => $session->messages_count ?? ($session->relationLoaded('messages') ? $session->messages->count() : null),
            'last_message_at' => $session->last_message_at?->toISOString(),
            'created_at' => $session->created_at?->toISOString(),
            'updated_at' => $session->updated_at?->toISOString(),
            'messages' => $session->relationLoaded('messages')
                ? $session->messages->map(fn (ContentChatMessage $message) => $this->messagePayload($message))->values()
                : null,
        ];
    }

    protected function messagePayload(ContentChatMessage $message): array
    {
        $sessionToken = $message->relationLoaded('session')
            ? $message->session?->token
            : $message->session()->value('token');

        $metadata = $message->metadata;
        // پیام‌های قدیمی idea_id عددی دارند
        if (is_array($metadata) && is_numeric($metadata['idea_id'] ?? null)) {
            $metadata['idea_id'] = ArticleIdea::whereKey((int) $metadata['idea_id'])->value('token');
        }

        return [
            'id' => $message->token,
            'session_id' => $sessionToken,
            'article_id' => $message->article_id ? $message->article?->token : null,
            'research_id' => $message->content_research_session_id ? $message->researchSession?->token : null,
            'role' => $message->role,
            'kind' => $message->kind,
            'content' => $message->content,
            'label' => $message->label,
            'entity' => $message->entity,
            'options' => $message->options,
            'answered' => (bool) $message->answered,
            'batch' => $message->batch,
            'metadata' => $metadata,
            'created_at' => $message->created_at?->toISOString(),
            'updated_at' => $message->updated_at?->toISOString(),
        ];
    }
}
