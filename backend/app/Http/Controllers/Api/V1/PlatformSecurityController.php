<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Platform\SecurityAuditQueryRequest;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlatformSecurityController
{
    public function overview(SecurityAuditQueryRequest $request): JsonResponse
    {
        $query = $this->filteredQuery($request);
        $activeSessions = $this->activeSessionCount();

        return response()->json(['success' => true, 'data' => [
            'range' => $this->range($request),
            'metrics' => [
                'total_audit_events' => (clone $query)->count(),
                'successful_logins' => (clone $query)->where('action', 'auth.login_succeeded')->count(),
                'failed_login_attempts' => (clone $query)->where('action', 'auth.login_failed')->count(),
                'active_sessions' => $activeSessions,
                'active_sessions_scope' => $activeSessions === null ? 'unknown' : 'database_browser_sessions_only',
                'active_security_alerts' => null,
                'critical_alerts' => null,
                'privileged_role_changes' => (clone $query)->whereIn('action', $this->privilegedActions())->count(),
                'unresolved_investigations' => null,
            ],
            'alerts' => [],
            'availability' => [
                'security_alerts' => false,
                'investigations' => false,
                'mfa_policy' => false,
                'note' => 'Dedicated security-alert, investigation, and MFA records are not stored by the current schema.',
            ],
        ]]);
    }

    public function index(SecurityAuditQueryRequest $request): JsonResponse
    {
        $logs = $this->filteredQuery($request)
            ->with(['actor:id,name,email,platform_role', 'tenant:id,name,business_code'])
            ->orderBy($request->input('sort', 'created_at'), $request->input('direction', 'desc'))
            ->paginate((int) $request->input('per_page', 25));

        $logs->getCollection()->transform(fn (AuditLog $log): array => $this->present($log));

        return response()->json(['success' => true, 'data' => $logs]);
    }

    public function show(Request $request, AuditLog $auditLog): JsonResponse
    {
        $auditLog->load(['actor:id,name,email,platform_role', 'tenant:id,name,business_code']);
        $related = AuditLog::query()
            ->where('audit_logs.id', '<>', $auditLog->getKey())
            ->whereBetween('created_at', [$auditLog->created_at?->copy()->subMinutes(30), $auditLog->created_at?->copy()->addMinutes(30)])
            ->when($auditLog->tenant_id, fn ($query) => $query->where('tenant_id', $auditLog->tenant_id))
            ->latest('created_at')
            ->limit(10)
            ->with(['actor:id,name,platform_role', 'tenant:id,name,business_code'])
            ->get()
            ->map(fn (AuditLog $log): array => $this->present($log))
            ->values();

        return response()->json(['success' => true, 'data' => [
            'event' => $this->present($auditLog, true),
            'related_events' => $related,
        ]]);
    }

    public function loginHistory(SecurityAuditQueryRequest $request): JsonResponse
    {
        $request->merge(['action' => null]);
        $logs = $this->filteredQuery($request)
            ->whereIn('action', ['auth.login_succeeded', 'auth.login_failed', 'auth.login_blocked', 'auth.logout'])
            ->with(['actor:id,name,email,platform_role'])
            ->latest('created_at')
            ->paginate((int) $request->input('per_page', 25));
        $logs->getCollection()->transform(fn (AuditLog $log): array => $this->present($log));

        return response()->json(['success' => true, 'data' => $logs]);
    }

    public function roleChanges(SecurityAuditQueryRequest $request): JsonResponse
    {
        $logs = $this->filteredQuery($request)
            ->whereIn('action', $this->privilegedActions())
            ->with(['actor:id,name,email,platform_role', 'tenant:id,name,business_code'])
            ->latest('created_at')
            ->paginate((int) $request->input('per_page', 25));
        $logs->getCollection()->transform(fn (AuditLog $log): array => $this->present($log));

        return response()->json(['success' => true, 'data' => $logs]);
    }

    public function policies(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'policies' => [
                ['key' => 'email_verification_required', 'label' => 'Email verification before business access', 'value' => (bool) config('auth.require_verified_email', false), 'status' => 'configured'],
                ['key' => 'session_lifetime_minutes', 'label' => 'Browser session lifetime', 'value' => (int) config('session.lifetime'), 'status' => 'configured'],
                ['key' => 'password_minimum_length', 'label' => 'Password minimum length', 'value' => 12, 'status' => 'configured_in_validation'],
                ['key' => 'auth_rate_limit', 'label' => 'Authentication rate limit', 'value' => '5 attempts per minute per email and IP', 'status' => 'configured'],
                ['key' => 'audit_retention', 'label' => 'Audit retention policy', 'value' => null, 'status' => 'unknown'],
                ['key' => 'mfa', 'label' => 'Multi-factor authentication', 'value' => null, 'status' => 'unavailable'],
            ],
            'read_only' => true,
            'note' => 'This view reports existing configuration only. Unsupported security controls are not presented as enabled.',
        ]]);
    }

    public function sessions(): JsonResponse
    {
        if (config('session.driver') !== 'database' || ! Schema::hasTable('sessions')) {
            return response()->json(['success' => true, 'data' => ['available' => false, 'scope' => 'unknown', 'sessions' => []]]);
        }
        $cutoff = now()->subMinutes((int) config('session.lifetime'))->timestamp;
        $sessions = DB::table('sessions')->join('users', 'users.id', '=', 'sessions.user_id')
            ->whereNotNull('sessions.user_id')->where('sessions.last_activity', '>=', $cutoff)
            ->orderByDesc('sessions.last_activity')->limit(100)
            ->get(['sessions.id as session_id', 'sessions.ip_address', 'sessions.user_agent', 'sessions.last_activity', 'users.id as user_id', 'users.name', 'users.email', 'users.platform_role'])
            ->map(fn ($session): array => [
                'reference' => Crypt::encryptString((string) $session->session_id),
                'user' => ['id' => $session->user_id, 'name' => $session->name, 'email' => $session->email, 'role' => $session->platform_role ?: 'business_user'],
                'ip_address' => $session->ip_address,
                'user_agent' => mb_substr((string) $session->user_agent, 0, 180),
                'last_activity' => date(DATE_ATOM, (int) $session->last_activity),
            ])->values();

        return response()->json(['success' => true, 'data' => ['available' => true, 'scope' => 'database_browser_sessions_only', 'sessions' => $sessions]]);
    }

    public function revokeSession(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        if (config('session.driver') !== 'database' || ! Schema::hasTable('sessions')) {
            abort(422, 'Database-backed session management is unavailable for the configured session driver.');
        }
        $data = $request->validate(['session_reference' => ['required', 'string'], 'password' => ['required', 'string'], 'confirm' => ['accepted']]);
        if (! Hash::check($data['password'], (string) $request->user()->password)) {
            throw ValidationException::withMessages(['password' => ['The Super Admin password is incorrect.']]);
        }
        try { $sessionId = Crypt::decryptString($data['session_reference']); } catch (\Throwable) { abort(422, 'The session reference is invalid or expired.'); }
        $session = DB::table('sessions')->where('id', $sessionId)->first(['id', 'user_id']);
        if (! $session) abort(404, 'The session is no longer active.');
        DB::table('sessions')->where('id', $sessionId)->delete();
        $auditLogger->record('platform.session_revoked', $request->user(), metadata: ['target_user_id' => $session->user_id]);
        return response()->json(['success' => true, 'message' => 'Session revoked.']);
    }

    public function export(SecurityAuditQueryRequest $request, AuditLogger $auditLogger): StreamedResponse
    {
        $rows = $this->filteredQuery($request)->with(['actor:id,name,email,platform_role', 'tenant:id,name,business_code'])->limit(5000)->get();
        $auditLogger->record('platform.security_audit_exported', $request->user(), metadata: [
            'row_count' => $rows->count(),
            'maximum_rows' => 5000,
            'date_range' => $this->range($request),
        ]);

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['event_id', 'timestamp', 'actor', 'actor_role', 'business', 'action', 'resource_type', 'resource_id', 'outcome', 'severity', 'correlation_id']);
            foreach ($rows as $log) {
                $event = $this->present($log);
                fputcsv($handle, array_map($this->csvValue(...), [
                    $event['id'], $event['timestamp'], $event['actor']['name'] ?? 'System', $event['actor']['role'] ?? '—',
                    $event['tenant']['name'] ?? 'Platform', $event['action'], $event['resource_type'] ?? '—', $event['resource_id'] ?? '—',
                    $event['outcome'], $event['severity'], $event['correlation_id'] ?? '—',
                ]));
            }
            fclose($handle);
        }, 'security-audit.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filteredQuery(SecurityAuditQueryRequest $request)
    {
        [$from, $to] = $this->dateBounds($request);
        return AuditLog::query()
            ->whereBetween('audit_logs.created_at', [$from, $to])
            ->when($request->filled('action'), fn ($query) => $query->where('audit_logs.action', $request->string('action')->toString()))
            ->when($request->filled('actor_id'), fn ($query) => $query->where('audit_logs.actor_user_id', $request->integer('actor_id')))
            ->when($request->filled('tenant_id'), fn ($query) => $query->where('audit_logs.tenant_id', $request->integer('tenant_id')))
            ->when($request->filled('resource_type'), fn ($query) => $query->where('audit_logs.subject_type', $request->string('resource_type')->toString()))
            ->when($request->filled('event_id'), fn ($query) => $query->whereKey($request->integer('event_id')))
            ->when($request->filled('severity'), fn ($query) => $query->where('audit_logs.metadata->severity', $request->string('severity')->toString()))
            ->when($request->filled('outcome'), fn ($query) => $query->where('audit_logs.metadata->outcome', $request->string('outcome')->toString()))
            ->when($request->filled('correlation_id'), fn ($query) => $query->where('audit_logs.metadata->correlation_id', $request->string('correlation_id')->toString()))
            ->when($request->filled('ip_address'), fn ($query) => $query->where('audit_logs.metadata->ip_address', $request->ip_address))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = '%'.$request->string('search')->toString().'%';
                $query->where(function ($nested) use ($search): void {
                    $nested->where('audit_logs.action', 'ilike', $search)
                        ->orWhere('audit_logs.subject_id', 'ilike', $search)
                        ->orWhereHas('actor', fn ($actor) => $actor->where('users.name', 'ilike', $search)->orWhere('users.email', 'ilike', $search))
                        ->orWhereHas('tenant', fn ($tenant) => $tenant->where('tenants.name', 'ilike', $search)->orWhere('tenants.business_code', 'ilike', $search));
                });
            });
    }

    private function dateBounds(SecurityAuditQueryRequest $request): array
    {
        $to = $request->filled('to') ? CarbonImmutable::parse($request->input('to'))->endOfDay() : CarbonImmutable::now();
        $from = $request->filled('from') ? CarbonImmutable::parse($request->input('from'))->startOfDay() : $to->subDays(((int) $request->input('days', 30)) - 1)->startOfDay();
        return [$from, $to];
    }

    private function range(SecurityAuditQueryRequest $request): array
    {
        [$from, $to] = $this->dateBounds($request);
        return ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()];
    }

    private function activeSessionCount(): ?int
    {
        if (config('session.driver') !== 'database' || ! Schema::hasTable('sessions')) return null;
        return (int) DB::table('sessions')->whereNotNull('user_id')->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->timestamp)->count();
    }

    private function privilegedActions(): array
    {
        return ['platform.super_admin_created', 'platform.super_admin_granted', 'platform.business_admin_created', 'platform.business_admin_updated', 'platform.business_admin_removed', 'platform.business_admin_invited', 'platform.business_invitation_revoked'];
    }

    private function present(AuditLog $log, bool $includeMetadata = false): array
    {
        $metadata = is_array($log->metadata) ? $this->redact($log->metadata) : [];
        $data = [
            'id' => $log->getKey(),
            'timestamp' => $log->created_at?->toIso8601String(),
            'actor' => $log->actor ? ['id' => $log->actor->getKey(), 'name' => $log->actor->name, 'email' => $log->actor->email, 'role' => $log->actor->platform_role ?: 'business_user'] : null,
            'tenant' => $log->tenant ? ['id' => $log->tenant->getKey(), 'name' => $log->tenant->name, 'business_code' => $log->tenant->business_code] : null,
            'action' => $log->action,
            'resource_type' => $log->subject_type,
            'resource_id' => $log->subject_id,
            'outcome' => $metadata['outcome'] ?? $this->outcomeFor($log->action),
            'severity' => $metadata['severity'] ?? $this->severityFor($log->action),
            'correlation_id' => $metadata['correlation_id'] ?? null,
        ];
        if ($includeMetadata) $data['metadata'] = $metadata;
        return $data;
    }

    private function outcomeFor(string $action): string
    {
        return match (true) {
            str_contains($action, 'failed'), str_contains($action, 'blocked') => 'failure',
            str_contains($action, 'succeeded'), str_contains($action, 'created'), str_contains($action, 'updated'), str_contains($action, 'approved'), str_contains($action, 'exported') => 'success',
            default => 'unknown',
        };
    }

    private function severityFor(string $action): string
    {
        return match (true) {
            str_contains($action, 'failed'), str_contains($action, 'blocked'), str_contains($action, 'deleted'), str_contains($action, 'revoked') => 'warning',
            str_contains($action, 'super_admin') => 'critical',
            default => 'info',
        };
    }

    private function redact(array $metadata): array
    {
        $blocked = ['password', 'password_confirmation', 'token', 'plain_token', 'access_token', 'authorization', 'secret', 'api_key', 'payment_credentials'];
        foreach ($metadata as $key => $value) {
            if (in_array(strtolower((string) $key), $blocked, true)) $metadata[$key] = '[REDACTED]';
            elseif (is_array($value)) $metadata[$key] = $this->redact($value);
        }
        return $metadata;
    }

    private function csvValue(mixed $value): string
    {
        $value = (string) $value;
        return preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
    }
}
