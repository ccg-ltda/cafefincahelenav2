<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\ActivityCatalog;
use App\Support\ActivityRedactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The only door into the audit trail. Services and controllers call it explicitly, INSIDE the transaction of the action,
 * so a rollback also removes the row (and if this insert fails, the action fails: no action without a record).
 */
class ActivityLogger
{
    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes  `{field: [before, after]}`, only what changed (see ActivityDiff)
     * @param  array<string, mixed>  $context  reason, filters, row counts…
     */
    public function log(string $module, string $action, ?Model $subject = null, array $changes = [], array $context = [], ?string $label = null, ?User $actor = null, ?string $actorLabel = null): ActivityLog
    {
        $actor ??= auth()->user();
        // Artisan commands have no route: they are recorded without IP, browser or request id.
        $request = request()->route() !== null ? request() : null;

        return ActivityLog::create([
            'occurred_at' => now(),
            'user_id' => $actor?->getKey(),
            'actor_label' => $actorLabel ?? ($actor ? $this->actorLabel($actor) : null),
            'module' => $module,
            'action' => $action,
            'subject_type' => $subject ? ActivityCatalog::typeOf($subject) : null,
            'subject_id' => $subject?->getKey(),
            'subject_label' => $label ?? ($subject ? ActivityCatalog::labelFor($subject) : null),
            'changes' => $changes ? ActivityRedactor::clean($changes) : null,
            'context' => $context ? ActivityRedactor::clean($context) : null,
            'ip' => $request?->ip(),
            'user_agent' => $request ? (mb_strimwidth((string) $request->userAgent(), 0, 255, '') ?: null) : null,
            'request_id' => $request ? $this->requestId($request) : null,
        ]);
    }

    /** "Ana Gómez" for an administrator; dispatch and delivery people appear with their role: "Carlos Rojas · Solo entrega". */
    private function actorLabel(User $actor): string
    {
        $name = (string) ($actor->name ?: $actor->email ?: $actor->username);

        return mb_strimwidth($actor->role instanceof Role && $actor->role !== Role::Admin ? $name.' · '.$actor->role->label() : $name, 0, 191, '…');
    }

    /** One id per HTTP request, so every row written by the same action can be grouped. */
    private function requestId(Request $request): string
    {
        if (! $request->attributes->has('activity_request_id')) {
            $request->attributes->set('activity_request_id', (string) Str::uuid());
        }

        return $request->attributes->get('activity_request_id');
    }
}
