<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Services\Admin\AnalyticsEventCatalog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AnalyticsEventController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $payload = $this->validatedEvent($request);
        $event = $this->persistEvent($payload, $request->user()?->id, null);

        return response()->json(['event_id' => $event?->id, 'accepted' => (bool) $event]);
    }

    public function anonymous(Request $request): JsonResponse
    {
        $payload = $this->validatedEvent($request);
        $anonymousId = $request->string('anonymous_id')->toString();

        if ($anonymousId === '') {
            return response()->json(['message' => 'anonymous_id is required.'], 422);
        }

        $event = $this->persistEvent($payload, null, $anonymousId);

        return response()->json(['event_id' => $event?->id, 'accepted' => (bool) $event]);
    }

    public function identify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'anonymous_id' => ['required', 'string', 'max:128'],
        ]);

        $updatedEvents = 0;
        $updatedSessions = 0;
        if (\Illuminate\Support\Facades\Schema::hasTable('analytics_events')) {
            $updatedEvents = DB::table('analytics_events')
                ->where('anonymous_id', $data['anonymous_id'])
                ->whereNull('user_id')
                ->update(['user_id' => $request->user()->id]);
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('analytics_sessions')) {
            $updatedSessions = DB::table('analytics_sessions')
                ->where('anonymous_id', $data['anonymous_id'])
                ->whereNull('user_id')
                ->update(['user_id' => $request->user()->id]);
        }

        return response()->json([
            'accepted' => true,
            'events_linked' => $updatedEvents,
            'sessions_linked' => $updatedSessions,
        ]);
    }

    public function batch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:100'],
            'events.*' => ['required', 'array'],
        ]);

        $accepted = [];
        foreach ($data['events'] as $item) {
            $validator = Validator::make($item, $this->eventRules());
            if ($validator->fails()) {
                continue;
            }
            $event = $this->persistEvent($validator->validated(), $request->user()?->id, null);
            if ($event) {
                $accepted[] = $event->id;
            }
        }

        return response()->json(['accepted' => count($accepted), 'event_ids' => $accepted]);
    }

    public function startSession(Request $request): JsonResponse
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('analytics_sessions')) {
            return response()->json(['message' => 'The analytics migration has not been run.'], 503);
        }

        $data = $request->validate([
            'session_key' => ['required', 'string', 'max:128'],
            'anonymous_id' => ['nullable', 'string', 'max:128'],
            'started_at' => ['nullable', 'date'],
        ]);
        $at = isset($data['started_at']) ? Carbon::parse($data['started_at']) : now();

        $session = AnalyticsSession::firstOrCreate(
            ['session_key' => $data['session_key']],
            [
                'user_id' => $request->user()?->id,
                'anonymous_id' => $data['anonymous_id'] ?? null,
                'started_at' => $at,
                'last_seen_at' => $at,
            ],
        );

        if ($request->user() && !$session->user_id) {
            $session->update(['user_id' => $request->user()->id]);
        }

        $this->persistEvent([
            'event_name' => AnalyticsEventCatalog::SESSION_STARTED,
            'event_key' => 'session_started:' . $data['session_key'],
            'session_key' => $data['session_key'],
            'source' => 'frontend',
            'feature' => 'session',
            'occurred_at' => $at->toIso8601String(),
            'properties' => [],
        ], $request->user()?->id, $data['anonymous_id'] ?? null);

        return response()->json(['session_id' => $session->id, 'session_key' => $session->session_key]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('analytics_sessions')) {
            return response()->json(['message' => 'The analytics migration has not been run.'], 503);
        }

        $data = $request->validate([
            'session_key' => ['required', 'string', 'max:128'],
            'occurred_at' => ['nullable', 'date'],
        ]);
        $at = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();
        $session = AnalyticsSession::where('session_key', $data['session_key'])->firstOrFail();
        $session->update(['last_seen_at' => $at, 'user_id' => $session->user_id ?: $request->user()?->id]);
        $this->persistEvent([
            'event_name' => AnalyticsEventCatalog::SESSION_HEARTBEAT,
            'event_key' => null,
            'session_key' => $data['session_key'],
            'source' => 'frontend',
            'feature' => 'session',
            'occurred_at' => $at->toIso8601String(),
            'properties' => [],
        ], $request->user()?->id ?: $session->user_id, $session->anonymous_id);

        return response()->json(['accepted' => true]);
    }

    public function endSession(Request $request): JsonResponse
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('analytics_sessions')) {
            return response()->json(['message' => 'The analytics migration has not been run.'], 503);
        }

        $data = $request->validate([
            'session_key' => ['required', 'string', 'max:128'],
            'ended_at' => ['nullable', 'date'],
        ]);
        $at = isset($data['ended_at']) ? Carbon::parse($data['ended_at']) : now();
        $session = AnalyticsSession::where('session_key', $data['session_key'])->firstOrFail();
        $session->update(['ended_at' => $at, 'last_seen_at' => $at, 'user_id' => $session->user_id ?: $request->user()?->id]);
        $this->persistEvent([
            'event_name' => AnalyticsEventCatalog::SESSION_ENDED,
            'event_key' => null,
            'session_key' => $data['session_key'],
            'source' => 'frontend',
            'feature' => 'session',
            'occurred_at' => $at->toIso8601String(),
            'properties' => [],
        ], $request->user()?->id ?: $session->user_id, $session->anonymous_id);

        return response()->json(['accepted' => true, 'duration_seconds' => $session->durationSeconds()]);
    }

    protected function validatedEvent(Request $request): array
    {
        return $request->validate($this->eventRules());
    }

    protected function eventRules(): array
    {
        return [
            'event_name' => ['required', 'string', 'max:80', function (string $attribute, mixed $value, \Closure $fail): void {
                if (!AnalyticsEventCatalog::isAllowed($value)) {
                    $fail('Invalid event_name.');
                }
            }],
            'event_key' => ['nullable', 'string', 'max:128'],
            'session_key' => ['nullable', 'string', 'max:128'],
            'source' => ['nullable', 'in:frontend,api,system'],
            'feature' => ['nullable', 'string', 'max:80'],
            'route' => ['nullable', 'string', 'max:255'],
            'entity_type' => ['nullable', 'string', 'max:80'],
            'entity_id' => ['nullable', 'integer'],
            'occurred_at' => ['nullable', 'date'],
            'properties' => ['nullable', 'array'],
        ];
    }

    protected function persistEvent(array $payload, ?int $userId, ?string $anonymousId): ?AnalyticsEvent
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('analytics_events')) {
            return null;
        }

        $occurredAt = isset($payload['occurred_at']) ? Carbon::parse($payload['occurred_at']) : now();
        $data = [
            'event_key' => $payload['event_key'] ?? null,
            'event_name' => $payload['event_name'],
            'user_id' => $userId,
            'anonymous_id' => $anonymousId,
            'session_key' => $payload['session_key'] ?? null,
            'source' => $payload['source'] ?? 'frontend',
            'feature' => $payload['feature'] ?? null,
            'route' => $payload['route'] ?? null,
            'entity_type' => $payload['entity_type'] ?? null,
            'entity_id' => $payload['entity_id'] ?? null,
            'occurred_at' => $occurredAt,
            'properties' => $payload['properties'] ?? null,
        ];

        return DB::transaction(function () use ($data): AnalyticsEvent {
            if ($data['event_key']) {
                $event = AnalyticsEvent::firstOrCreate(['event_key' => $data['event_key']], $data);
            } else {
                $event = AnalyticsEvent::create($data);
            }

            if ($data['session_key'] && \Illuminate\Support\Facades\Schema::hasTable('analytics_sessions')) {
                $this->touchSession($data['session_key'], $data['user_id'], $data['anonymous_id'], $data['event_name'], $data['source'], $data['occurred_at']);
            }

            return $event;
        });
    }

    protected function touchSession(string $sessionKey, ?int $userId, ?string $anonymousId, string $eventName, string $source, Carbon $at): void
    {
        $session = AnalyticsSession::firstOrCreate(
            ['session_key' => $sessionKey],
            ['user_id' => $userId, 'anonymous_id' => $anonymousId, 'started_at' => $at, 'last_seen_at' => $at],
        );

        $session->last_seen_at = $at;
        $session->user_id = $session->user_id ?: $userId;
        $session->anonymous_id = $session->anonymous_id ?: $anonymousId;
        $session->action_count += 1;
        $session->meaningful_action_count += in_array($eventName, AnalyticsEventCatalog::meaningful(), true) ? 1 : 0;
        $session->page_view_count += $eventName === AnalyticsEventCatalog::PAGE_VIEWED ? 1 : 0;
        $session->api_interaction_count += $source === 'api' ? 1 : 0;
        $session->funnel_stage = $this->stageForEvent($eventName) ?: $session->funnel_stage;
        $session->save();
    }

    protected function stageForEvent(string $eventName): ?string
    {
        foreach (AnalyticsEventCatalog::stageDefinitions() as $stage => $definition) {
            if (in_array($eventName, $definition['events'], true)) {
                return $stage;
            }
        }
        return null;
    }
}
