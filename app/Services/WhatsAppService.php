<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Models\WhatsappMenuClick;
use App\Models\WhatsappRequest;
use App\Support\ActivityDiff;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Rules of the WhatsApp module: registering a request, its transitions and the summary of the admin panel. */
class WhatsAppService
{
    public const ORDER_REFERENCE_REGEX = '/CFH-[A-Z0-9]{12}/i';

    public function __construct(private readonly CustomerListing $customers, private readonly ActivityLogger $activity) {}

    /** The reference typed in the form or, when empty, the first "CFH-XXXXXXXXXXXX" found in the text. */
    public function orderReference(?string $given, string $message): ?string
    {
        $found = filled($given) ? $given : (preg_match(self::ORDER_REFERENCE_REGEX, $message, $match) ? $match[0] : null);

        return $found ? mb_strtoupper(trim($found)) : null;
    }

    /** The customer with this phone (most recent first; one with valid orders is preferred). */
    public function customerFor(string $phone): ?Customer
    {
        return $this->customers->base()->where('customers.phone', $phone)->orderByDesc('s.last_order_at')->first()
            ?? Customer::query()->where('phone', $phone)->orderByDesc('updated_at')->first();
    }

    /** @param  array{name: string, phone: string, topic: string, message: string, order_reference?: ?string}  $data */
    public function register(array $data, User $by): WhatsappRequest
    {
        $phone = Phone::normalize($data['phone']);

        return DB::transaction(function () use ($data, $phone, $by) {
            $request = WhatsappRequest::create([
                'name' => trim($data['name']), 'phone' => $phone, 'topic' => $data['topic'], 'message' => trim($data['message']),
                'order_reference' => $this->orderReference($data['order_reference'] ?? null, $data['message']),
                'customer_id' => $this->customerFor($phone)?->id, 'created_by' => $by->id, 'status' => 'new',
            ]);
            // The message and the phone are personal data: the trail keeps the topic and the order only.
            $this->activity->log('whatsapp', 'created', $request, ['topic' => [null, $request->topic]], array_filter(['order' => $request->order_reference]), actor: $by);

            return $request;
        });
    }

    /** The administrator takes the request: it becomes theirs and goes to "in progress". */
    public function take(WhatsappRequest $request, User $user): WhatsappRequest
    {
        $this->refuseIfResolved($request, 'Reabre la solicitud antes de tomarla.');
        return $this->transition($request, 'taken', ['assigned_to' => $user->id, 'status' => 'in_progress'], $user);
    }

    /** Changes the person in charge (an administrator). A new request becomes "in progress". */
    public function assign(WhatsappRequest $request, User $user): WhatsappRequest
    {
        if (! $user->hasPermission('whatsapp.manage')) {
            throw ValidationException::withMessages(['user_id' => ['Solo puedes asignar a un administrador.']]);
        }
        return $this->transition($request, 'assigned', ['assigned_to' => $user->id, 'status' => $request->status === 'resolved' ? 'resolved' : 'in_progress']);
    }

    public function resolve(WhatsappRequest $request, ?string $note): WhatsappRequest
    {
        return $this->transition($request, 'resolved', ['status' => 'resolved', 'resolved_at' => now(), 'resolution_note' => filled($note) ? trim($note) : null], null, ['resolved_at']);
    }

    public function reopen(WhatsappRequest $request): WhatsappRequest
    {
        return $this->transition($request, 'reopened', ['status' => $request->assigned_to ? 'in_progress' : 'new', 'resolved_at' => null], null, ['resolved_at']);
    }

    /** Internal notes are free text that may hold personal data: the trail only says they changed. */
    public function updateNotes(WhatsappRequest $request, ?string $notes): WhatsappRequest
    {
        return DB::transaction(function () use ($request, $notes) {
            $request->fill(['internal_notes' => filled($notes) ? $notes : null]);
            $changed = $request->isDirty('internal_notes');
            $request->save();
            if ($changed) {
                $this->activity->log('whatsapp', 'notes_updated', $request);
            }

            return $request;
        });
    }

    /** Saves a state change and records it as one audit row (same transaction). `$skip` fields are changed but not listed. */
    private function transition(WhatsappRequest $request, string $action, array $attributes, ?User $actor = null, array $skip = []): WhatsappRequest
    {
        return DB::transaction(function () use ($request, $action, $attributes, $actor, $skip) {
            $request->fill($attributes);
            $changes = ActivityDiff::dirty($request, array_values(array_diff(array_keys($attributes), $skip)));
            $request->save();
            $this->activity->log('whatsapp', $action, $request, $changes, [], actor: $actor);

            return $request;
        });
    }

    private function refuseIfResolved(WhatsappRequest $request, string $message): void
    {
        if ($request->status === 'resolved') {
            throw ValidationException::withMessages(['status' => [$message]]);
        }
    }

    /** Anonymous click of the storefront menu: topic, page path (no query, no fragment) and time. Nothing else. */
    public function recordClick(string $topic, string $pagePath): void
    {
        WhatsappMenuClick::create(['topic' => $topic, 'page_path' => $pagePath]);
    }

    /** "/producto/x?utm=1#a" -> "/producto/x". Returns null when it is not a short plain path. */
    public function cleanPath(string $path): ?string
    {
        $path = strtok(strtok($path, '#') ?: '', '?') ?: '';

        return preg_match('#^/[A-Za-z0-9\-._~/%]{0,118}$#', $path) ? $path : null;
    }

    /** @return array{new: int, in_progress: int, resolved_30d: int, clicks_30d: int, clicks_by_topic: array<string, int>} */
    public function summary(): array
    {
        $since = now()->subDays(30);
        $byTopic = WhatsappMenuClick::query()->where('created_at', '>=', $since)->selectRaw('topic, count(*) as total')->groupBy('topic')->pluck('total', 'topic');
        $clicks = [];
        foreach (WhatsappRequest::TOPICS as $topic) {
            $clicks[$topic] = (int) ($byTopic[$topic] ?? 0);
        }

        return [
            'new' => WhatsappRequest::query()->where('status', 'new')->count(),
            'in_progress' => WhatsappRequest::query()->where('status', 'in_progress')->count(),
            'resolved_30d' => WhatsappRequest::query()->where('status', 'resolved')->where('resolved_at', '>=', $since)->count(),
            'clicks_30d' => array_sum($clicks),
            'clicks_by_topic' => $clicks,
        ];
    }

    /** The order of a reference, only when it exists. */
    public function orderFor(?string $reference): ?Order
    {
        return $reference ? Order::query()->where('reference', $reference)->first() : null;
    }
}
