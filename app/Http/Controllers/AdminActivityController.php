<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\ActivityCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Read-only audit trail for administrators. There is no endpoint that edits or deletes a record. */
class AdminActivityController extends Controller
{
    public const PER_PAGE = 50;

    private const TIMEZONE = 'America/Bogota';

    private const CONTEXT_LABELS = [
        'reason' => 'Motivo', 'sku' => 'SKU', 'variant' => 'Presentación', 'filter' => 'Filtro', 'search' => 'Búsqueda', 'rows' => 'Filas exportadas',
        'email' => 'Correo intentado', 'login' => 'Usuario o correo intentado', 'delivery' => 'Repartidor', 'carrier' => 'Transportadora', 'tracking_number' => 'Guía', 'note' => 'Nota', 'units' => 'Unidades', 'order' => 'Pedido', 'image' => 'Imagen', 'admin' => 'Administrador',
        'module' => 'Módulo', 'action' => 'Acción', 'from' => 'Desde', 'to' => 'Hasta', 'include_auth' => 'Incluye accesos',
    ];

    public function __construct(private readonly ActivityLogger $activity) {}

    private function filters(Request $request): array
    {
        return $request->validate([
            'admin' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'module' => ['sometimes', 'nullable', Rule::in(array_keys(ActivityCatalog::MODULES))],
            'action' => ['sometimes', 'nullable', Rule::in(array_keys(ActivityCatalog::ACTIONS))],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'include_auth' => ['sometimes', 'nullable', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ]);
    }

    /** Every filter goes through query-builder bindings. Accesses ("auth") stay out unless asked for or chosen as the module. */
    private function filtered(array $filters): Builder
    {
        $module = $filters['module'] ?? null;

        return ActivityLog::query()
            ->when(filled($filters['admin'] ?? null), fn (Builder $query) => $query->where('user_id', (int) $filters['admin']))
            ->when(filled($module), fn (Builder $query) => $query->where('module', $module))
            ->when(blank($module) && ! ($filters['include_auth'] ?? false), fn (Builder $query) => $query->where('module', '<>', 'auth'))
            ->when(filled($filters['action'] ?? null), fn (Builder $query) => $query->where('action', $filters['action']))
            ->when(filled($filters['from'] ?? null), fn (Builder $query) => $query->where('occurred_at', '>=', Carbon::parse($filters['from'], self::TIMEZONE)->startOfDay()->utc()))
            ->when(filled($filters['to'] ?? null), fn (Builder $query) => $query->where('occurred_at', '<=', Carbon::parse($filters['to'], self::TIMEZONE)->endOfDay()->utc()))
            ->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $page = $this->filtered($filters)->paginate(self::PER_PAGE, ['*'], 'page', (int) ($filters['page'] ?? 1));
        $links = ActivityCatalog::links($page->getCollection());

        return response()->json([
            'data' => $page->getCollection()->map(fn (ActivityLog $row) => $this->row($row, $links))->values(),
            'options' => [
                'admins' => User::query()->orderBy('name')->get(['id', 'name']), // every person of the panel, active or not: their old actions stay filterable
                'modules' => collect(ActivityCatalog::MODULES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
                'actions' => collect(ActivityCatalog::MODULE_ACTIONS)->map(fn ($actions) => collect($actions)->map(fn ($action) => ['value' => $action, 'label' => ActivityCatalog::ACTIONS[$action]])->values()),
            ],
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(int $entry): JsonResponse
    {
        $row = ActivityLog::query()->findOrFail($entry);
        $links = ActivityCatalog::links(collect([$row]));

        return response()->json(['data' => $this->row($row, $links) + [
            'changes' => $this->changes($row),
            'context' => collect($row->context ?? [])->map(fn ($value, $key) => ['label' => self::CONTEXT_LABELS[$key] ?? $key, 'value' => is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE)])->values(),
            'technical' => ['ip' => $row->ip, 'browser' => $this->browser($row->user_agent), 'request_id' => $row->request_id],
        ]])->header('Cache-Control', 'private, no-store');
    }

    /** CSV of the current filter (UTF-8 with BOM, ";", formulas neutralised). Exporting is itself recorded. */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $query = $this->filtered($filters);
        $lastId = (int) ActivityLog::query()->max('id');
        $query->where('id', '<=', $lastId); // the row written by this very export is not part of it
        $rows = (clone $query)->count();
        $this->activity->log('activity', 'exported', null, [], array_filter([
            'admin' => filled($filters['admin'] ?? null) ? User::query()->whereKey($filters['admin'])->value('name') : null,
            'module' => $filters['module'] ?? null, 'action' => $filters['action'] ?? null, 'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null,
            'include_auth' => ($filters['include_auth'] ?? false) ? 'Sí' : null, 'rows' => $rows,
        ], fn ($value) => $value !== null), 'Historial: exportación CSV');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Fecha', 'Administrador', 'Módulo', 'Acción', 'Recurso', 'Cambios', 'Contexto', 'IP'], ';');
            foreach ($query->cursor() as $row) {
                fputcsv($out, array_map([$this, 'safeCell'], [
                    $this->local($row->occurred_at), $row->actor_label ?? '', ActivityCatalog::MODULES[$row->module] ?? $row->module, ActivityCatalog::ACTIONS[$row->action] ?? $row->action,
                    $row->subject_label ?? '',
                    collect($this->changes($row))->map(fn ($change) => "{$change['label']}: {$change['before']} → {$change['after']}")->implode(' | '),
                    collect($row->context ?? [])->map(fn ($value, $key) => (self::CONTEXT_LABELS[$key] ?? $key).': '.(is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE)))->implode(' | '),
                    $row->ip ?? '',
                ]), ';');
            }
            fclose($out);
        }, 'historial-'.now(self::TIMEZONE)->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    /** Text that came from forms (names, notes, attempted emails) must not run as a formula when the CSV is opened in Excel. */
    private function safeCell(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    /** @param  array<string, string>  $links */
    private function row(ActivityLog $row, array $links): array
    {
        return [
            'id' => $row->id, 'occurred_at' => $row->occurred_at?->toIso8601String(), 'occurred_text' => $this->local($row->occurred_at),
            'actor' => $row->actor_label, 'user_id' => $row->user_id,
            'module' => $row->module, 'module_label' => ActivityCatalog::MODULES[$row->module] ?? $row->module,
            'action' => $row->action, 'action_label' => ActivityCatalog::ACTIONS[$row->action] ?? $row->action,
            'subject_label' => $row->subject_label, 'subject_type' => $row->subject_type, 'subject_id' => $row->subject_id,
            'link' => $row->subject_type ? ($links["{$row->subject_type}:{$row->subject_id}"] ?? null) : null,
            'failed' => $row->action === 'login_failed',
        ];
    }

    /** "19/11/2026, 3:09 p. m." in Colombian time. */
    private function local(?Carbon $at): string
    {
        return $at ? str_replace(['AM', 'PM'], ['a. m.', 'p. m.'], $at->copy()->setTimezone(self::TIMEZONE)->format('d/m/Y, g:i A')) : '';
    }

    /** @return list<array{field: string, label: string, before: string, after: string}> */
    private function changes(ActivityLog $row): array
    {
        $changes = $row->getAttribute('changes') ?? [];
        $names = $this->namesFor($changes);

        return collect($changes)->map(fn ($pair, $field) => [
            'field' => $field, 'label' => ActivityCatalog::FIELDS[$field] ?? $field,
            'before' => $this->display($field, $pair[0] ?? null, $names), 'after' => $this->display($field, $pair[1] ?? null, $names),
        ])->values()->all();
    }

    /** Readable text of one value: COP with thousands, dates in Colombian time, ids as the current name of what they point to. */
    private function display(string $field, mixed $value, Collection $names): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (str_ends_with($field, '_cop') && is_numeric($value)) {
            return '$ '.number_format((float) $value, 0, ',', '.');
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value)) {
            return $this->local(Carbon::parse($value));
        }
        if ($names->has("$field:$value")) {
            return $names->get("$field:$value");
        }

        return (string) $value;
    }

    /** Current names behind id fields (category, product, person in charge), one query per kind. */
    private function namesFor(array $changes): Collection
    {
        $ids = fn (string $field) => collect($changes[$field] ?? [])->filter(fn ($id) => is_numeric($id))->unique()->all();
        $names = collect();
        foreach (['category_id' => Category::query(), 'product_id' => Product::query(), 'assigned_to' => User::query()] as $field => $query) {
            foreach (($ids($field) ? $query->whereIn('id', $ids($field))->get(['id', 'name']) : []) as $model) {
                $names->put("$field:{$model->id}", $model->name);
            }
        }

        return $names;
    }

    /** "Chrome 120 · Windows" from the stored user agent (kept short on purpose). */
    private function browser(?string $agent): ?string
    {
        if (! $agent) {
            return null;
        }
        $browser = match (true) {
            (bool) preg_match('~Edg/([\d]+)~', $agent, $m) => "Edge $m[1]",
            (bool) preg_match('~(?:OPR|Opera)/([\d]+)~', $agent, $m) => "Opera $m[1]",
            (bool) preg_match('~Firefox/([\d]+)~', $agent, $m) => "Firefox $m[1]",
            (bool) preg_match('~Chrome/([\d]+)~', $agent, $m) => "Chrome $m[1]",
            (bool) preg_match('~Version/([\d]+).*Safari~', $agent, $m) => "Safari $m[1]",
            default => null,
        };
        $system = match (true) {
            str_contains($agent, 'Windows') => 'Windows', str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS', str_contains($agent, 'Mac OS') => 'macOS', str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return collect([$browser, $system])->filter()->implode(' · ') ?: mb_strimwidth($agent, 0, 60, '…');
    }
}
