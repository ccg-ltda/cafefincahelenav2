<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WhatsappQuickReply;
use App\Models\WhatsappRequest;
use App\Services\ActivityLogger;
use App\Services\CustomerListing;
use App\Services\WhatsAppService;
use App\Support\ActivityDiff;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Admin-only WhatsApp module. Every endpoint sits behind EnsureAdministrator. */
class AdminWhatsAppController extends Controller
{
    public const PER_PAGE = 15;

    public function __construct(private readonly WhatsAppService $whatsapp, private readonly CustomerListing $customers, private readonly ActivityLogger $activity) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'filter' => ['sometimes', 'nullable', Rule::in(['open', 'all', ...WhatsappRequest::TOPICS])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ]);
        $filter = $filters['filter'] ?? 'open';

        $query = WhatsappRequest::query()->with('assignee:id,name');
        if ($filter === 'open') {
            $query->whereIn('status', ['new', 'in_progress']);
        } elseif ($filter !== 'all') {
            $query->where('topic', $filter);
        }
        $page = $query->orderByRaw("case status when 'new' then 0 when 'in_progress' then 1 else 2 end")->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return response()->json([
            'data' => $page->getCollection()->map(fn (WhatsappRequest $row) => $this->row($row))->values(),
            'summary' => $this->whatsapp->summary(),
            'quick_replies' => $this->quickReplies(),
            'admins' => User::query()->where('role', 'admin')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'phone' => is_string($request->input('phone')) ? Phone::normalize($request->input('phone')) : $request->input('phone'),
            'order_reference' => blank($request->input('order_reference')) ? null : mb_strtoupper(trim((string) $request->input('order_reference'))),
        ]);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'phone' => ['required', 'string', 'regex:'.Phone::MOBILE_REGEX],
            'topic' => ['required', Rule::in(WhatsappRequest::TOPICS)],
            'message' => ['required', 'string', 'min:2', 'max:2000'],
            'order_reference' => ['nullable', 'string', 'regex:/^CFH-[A-Z0-9]{12}$/'],
        ], ['phone.regex' => 'Ingresa un celular colombiano de 10 dígitos que empiece por 3.', 'order_reference.regex' => 'La referencia debe verse como CFH-XXXXXXXXXXXX.'], [
            'name' => 'nombre', 'phone' => 'teléfono', 'topic' => 'tema', 'message' => 'mensaje', 'order_reference' => 'pedido',
        ]);

        $created = $this->whatsapp->register($data, $request->user());

        return response()->json(['data' => $this->detail($created->fresh('assignee')), 'summary' => $this->whatsapp->summary()], 201)->header('Cache-Control', 'private, no-store');
    }

    public function show(WhatsappRequest $whatsappRequest): JsonResponse
    {
        return $this->respond($whatsappRequest);
    }

    public function take(Request $request, WhatsappRequest $whatsappRequest): JsonResponse
    {
        return $this->respond($this->whatsapp->take($whatsappRequest, $request->user()));
    }

    public function assign(Request $request, WhatsappRequest $whatsappRequest): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);

        return $this->respond($this->whatsapp->assign($whatsappRequest, User::findOrFail($data['user_id'])));
    }

    public function resolve(Request $request, WhatsappRequest $whatsappRequest): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->respond($this->whatsapp->resolve($whatsappRequest, $data['note'] ?? null));
    }

    public function reopen(WhatsappRequest $whatsappRequest): JsonResponse
    {
        return $this->respond($this->whatsapp->reopen($whatsappRequest));
    }

    public function notes(Request $request, WhatsappRequest $whatsappRequest): JsonResponse
    {
        $data = $request->validate(['internal_notes' => ['nullable', 'string', 'max:5000']]);

        return $this->respond($this->whatsapp->updateNotes($whatsappRequest, $data['internal_notes'] ?? null));
    }

    public function updateQuickReply(Request $request, WhatsappQuickReply $quickReply): JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'min:2', 'max:120'], 'body' => ['required', 'string', 'min:2', 'max:1000']], [], ['title' => 'título', 'body' => 'texto']);
        DB::transaction(function () use ($quickReply, $data) {
            $quickReply->fill(['title' => trim($data['title']), 'body' => trim($data['body'])]);
            $changes = ActivityDiff::dirty($quickReply);
            $quickReply->save();
            if ($changes) {
                $this->activity->log('whatsapp', 'quick_reply_updated', $quickReply, $changes);
            }
        });

        return response()->json(['data' => $this->quickReplies()])->header('Cache-Control', 'private, no-store');
    }

    private function respond(WhatsappRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->detail($request->fresh('assignee')), 'summary' => $this->whatsapp->summary()])->header('Cache-Control', 'private, no-store');
    }

    private function quickReplies(): array
    {
        return WhatsappQuickReply::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'topic', 'title', 'body'])->toArray();
    }

    private function row(WhatsappRequest $row): array
    {
        return [
            'id' => $row->id, 'name' => $row->name, 'phone' => $row->phone, 'topic' => $row->topic, 'status' => $row->status,
            'excerpt' => mb_strimwidth(preg_replace('/\s+/u', ' ', $row->message), 0, 140, '…'),
            'assignee' => $row->assignee ? ['id' => $row->assignee->id, 'name' => $row->assignee->name] : null,
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    private function detail(WhatsappRequest $row): array
    {
        $order = $this->whatsapp->orderFor($row->order_reference);
        $customer = $row->customer_id ? $row->customer : $this->whatsapp->customerFor($row->phone);
        $listed = $customer && $this->customers->base()->where('customers.id', $customer->id)->exists();

        return $this->row($row) + [
            'message' => $row->message, 'internal_notes' => $row->internal_notes, 'resolution_note' => $row->resolution_note,
            'resolved_at' => $row->resolved_at?->toIso8601String(), 'created_by' => $row->created_by ? User::query()->whereKey($row->created_by)->value('name') : null,
            'order_reference' => $row->order_reference, 'order' => $order ? ['id' => $order->id, 'reference' => $order->reference] : null,
            // The link to the customer file only exists when that customer has valid orders (the file would answer 404 otherwise).
            'customer' => $customer ? ['id' => $customer->id, 'name' => $customer->name, 'linkable' => $listed] : null,
            'quick_reply' => WhatsappQuickReply::query()->where('topic', $row->topic)->orderBy('sort_order')->orderBy('id')->first(['title', 'body']),
        ];
    }
}
