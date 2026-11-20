<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveCouponRequest;
use App\Models\Coupon;
use App\Services\ActivityLogger;
use App\Services\CouponService;
use App\Support\ActivityDiff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminCouponController extends Controller
{
    public const PER_PAGE = 25;

    private const USED_MESSAGE = 'Este cupón ya se usó en pedidos; puedes desactivarlo.';

    public function __construct(private readonly CouponService $coupons, private readonly ActivityLogger $activity) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::in(['current', 'scheduled', 'expired', 'exhausted', 'inactive'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ]);

        $query = $this->query();
        if (filled($filters['search'] ?? null)) {
            $like = '%'.addcslashes(trim($filters['search']), '\\%_').'%';
            $query->where(fn (Builder $group) => $group->where('code', 'like', $like)->orWhere('description', 'like', $like));
        }
        if (filled($filters['status'] ?? null)) {
            $query->inStatus($filters['status']);
        }

        $page = $query->orderByDesc('coupons.id')->paginate(self::PER_PAGE, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Coupon $coupon) => $this->row($coupon))->values(),
            'summary' => $this->coupons->summary(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Coupon $coupon): JsonResponse
    {
        return response()->json(['data' => $this->form($this->query()->findOrFail($coupon->id))])->header('Cache-Control', 'private, no-store');
    }

    public function store(SaveCouponRequest $request): JsonResponse
    {
        $payload = $this->payload($request->validated());
        $this->coupons->validateForSave($payload);
        $coupon = $this->persist(fn () => DB::transaction(function () use ($payload, $request) {
            $coupon = Coupon::create($payload + ['created_by' => $request->user()->id]);
            $this->activity->log('coupons', 'created', $coupon, ActivityDiff::between([], $payload));

            return $coupon;
        }));

        return response()->json(['data' => $this->row($this->query()->findOrFail($coupon->id)), 'summary' => $this->coupons->summary()], 201)
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(SaveCouponRequest $request, Coupon $coupon): JsonResponse
    {
        $payload = $this->payload($request->validated());
        $this->coupons->validateForSave($payload, $coupon->id);
        $this->persist(fn () => DB::transaction(function () use ($coupon, $payload) {
            $coupon->fill($payload);
            $changes = ActivityDiff::dirty($coupon);
            $coupon->save();
            if ($changes) {
                $this->activity->log('coupons', 'updated', $coupon, $changes);
            }
        }));

        return response()->json(['data' => $this->row($this->query()->findOrFail($coupon->id)), 'summary' => $this->coupons->summary()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function status(Request $request, Coupon $coupon): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        DB::transaction(function () use ($coupon, $data) {
            $coupon->fill(['is_active' => $data['is_active']]);
            $changed = $coupon->isDirty('is_active');
            $coupon->save();
            if ($changed) {
                $this->activity->log('coupons', $coupon->is_active ? 'activated' : 'deactivated', $coupon);
            }
        });

        return response()->json(['data' => $this->row($this->query()->findOrFail($coupon->id)), 'summary' => $this->coupons->summary()])
            ->header('Cache-Control', 'private, no-store');
    }

    /** A coupon that was used in an order cannot be deleted (it can be deactivated). */
    public function destroy(Coupon $coupon): JsonResponse
    {
        if ($coupon->redemptions()->exists() || $coupon->orders()->exists()) {
            return response()->json(['message' => self::USED_MESSAGE], 409);
        }

        try {
            DB::transaction(function () use ($coupon) {
                $this->activity->log('coupons', 'deleted', $coupon); // the label is frozen before the row disappears
                $coupon->delete();
            });
        } catch (QueryException $error) {
            // An order used it between the check and the delete: the foreign key (RESTRICT) refuses.
            if ((string) $error->getCode() === '23000') {
                return response()->json(['message' => self::USED_MESSAGE], 409);
            }
            throw $error;
        }

        return response()->json(['summary' => $this->coupons->summary()])->header('Cache-Control', 'private, no-store');
    }

    private function query(): Builder
    {
        return Coupon::query()->withCount(['redemptions as uses_count' => fn (Builder $query) => $query->whereNull('released_at')])
            ->withExists(['redemptions as has_redemptions', 'orders as has_orders']);
    }

    /** The unique index is the last guard against two admins saving the same code at once. */
    private function persist(callable $write): mixed
    {
        try {
            return $write();
        } catch (QueryException $error) {
            if ((string) $error->getCode() === '23000') {
                throw ValidationException::withMessages(['code' => ['Ya existe un cupón con este código.']]);
            }
            throw $error;
        }
    }

    private function payload(array $data): array
    {
        return [
            'code' => $data['code'],
            'description' => $data['description'] ?? null,
            'discount_type' => $data['discount_type'],
            'discount_value' => (int) $data['discount_value'],
            'max_discount_cop' => isset($data['max_discount_cop']) ? (int) $data['max_discount_cop'] : null,
            'min_subtotal_cop' => (int) $data['min_subtotal_cop'],
            'max_uses' => isset($data['max_uses']) ? (int) $data['max_uses'] : null,
            'exclude_promoted_items' => (bool) $data['exclude_promoted_items'],
            'is_active' => (bool) $data['is_active'],
            'starts_at' => $this->coupons->parseLocal($data['starts_at'] ?? null),
            'ends_at' => $this->coupons->parseLocal($data['ends_at'] ?? null),
        ];
    }

    private function row(Coupon $coupon): array
    {
        $uses = (int) $coupon->uses_count;

        return [
            'id' => $coupon->id, 'code' => $coupon->code, 'description' => $coupon->description,
            'discount_type' => $coupon->discount_type, 'discount_value' => $coupon->discount_value, 'discount_label' => $this->coupons->label($coupon),
            'max_discount_cop' => $coupon->max_discount_cop, 'min_subtotal_cop' => $coupon->min_subtotal_cop,
            'max_uses' => $coupon->max_uses, 'uses' => $uses,
            'exclude_promoted_items' => $coupon->exclude_promoted_items,
            'starts_at' => $coupon->starts_at?->toIso8601String(), 'ends_at' => $coupon->ends_at?->toIso8601String(),
            'is_active' => $coupon->is_active, 'status' => $this->coupons->status($coupon, $uses),
            'used_in_orders' => (bool) ($coupon->has_redemptions || $coupon->has_orders),
        ];
    }

    private function form(Coupon $coupon): array
    {
        return $this->row($coupon) + ['starts_at_local' => $this->coupons->formatLocal($coupon->starts_at), 'ends_at_local' => $this->coupons->formatLocal($coupon->ends_at)];
    }
}
