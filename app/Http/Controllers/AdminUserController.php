<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Services\UserService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** People of the panel (users.manage). No endpoint deletes a person: they are deactivated. */
class AdminUserController extends Controller
{
    public const PER_PAGE = 25;

    public function __construct(private readonly UserService $users) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'role' => ['sometimes', 'nullable', Rule::enum(Role::class)],
            'status' => ['sometimes', 'nullable', 'in:active,inactive,all'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ]);
        $status = $filters['status'] ?? 'active';

        $query = User::query()
            ->when(filled($filters['search'] ?? null), function (Builder $query) use ($filters) {
                $like = '%'.addcslashes(trim($filters['search']), '\\%_').'%';
                $query->where(fn (Builder $group) => $group->where('name', 'like', $like)->orWhere('username', 'like', $like));
            })
            ->when(filled($filters['role'] ?? null), fn (Builder $query) => $query->where('role', $filters['role']))
            ->when($status !== 'all', fn (Builder $query) => $query->where('is_active', $status === 'active'));

        $page = $query->orderByDesc('is_active')->orderBy('name')->orderBy('id')->paginate(self::PER_PAGE, ['*'], 'page', (int) ($filters['page'] ?? 1));
        $active = fn () => User::query()->where('is_active', true);

        return response()->json([
            'data' => $page->getCollection()->map(fn (User $user) => $this->row($user, $request->user()))->values(),
            'summary' => [
                'active' => $active()->count(), 'admin' => $active()->where('role', 'admin')->count(),
                'dispatch' => $active()->where('role', 'dispatch')->count(), 'delivery' => $active()->where('role', 'delivery')->count(),
            ],
            'roles' => collect(Role::cases())->map(fn (Role $role) => ['value' => $role->value, 'label' => $role->label(), 'description' => $role->description()])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, User $user): JsonResponse
    {
        return $this->respond($request, $user);
    }

    /** Live check of the username while the form is typed. */
    public function usernameCheck(Request $request): JsonResponse
    {
        $data = $request->validate(['username' => ['required', 'string', 'max:40'], 'ignore' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999999999999']]);
        $username = mb_strtolower(trim($data['username']));
        $valid = (bool) preg_match('/^[a-z0-9.-]{3,40}$/', $username);
        $taken = $valid && User::query()->where('username', $username)->when(! empty($data['ignore']), fn ($query) => $query->where('id', '<>', $data['ignore']))->exists();

        return response()->json(['valid' => $valid, 'available' => $valid && ! $taken])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null) + $request->validate([
            'password' => ['required', 'string', 'min:'.UserService::MIN_PASSWORD, 'max:100', new StrongPassword],
        ], ['password.min' => 'La contraseña temporal debe tener al menos '.UserService::MIN_PASSWORD.' caracteres.'], ['password' => 'contraseña temporal']);

        $created = $this->users->create($data, $request->user());

        return $this->respond($request, $created, 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        return $this->respond($request, $this->users->update($user, $this->validated($request, $user), $request->user()));
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        return $this->respond($request, $this->users->deactivate($user, $request->user()));
    }

    public function reactivate(Request $request, User $user): JsonResponse
    {
        return $this->respond($request, $this->users->reactivate($user, $request->user()));
    }

    /** The temporary password comes back ONCE in this response (given by the admin or generated); it is never stored in clear. */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['password' => ['nullable', 'string', 'min:'.UserService::MIN_PASSWORD, 'max:100', new StrongPassword]], ['password.min' => 'La contraseña temporal debe tener al menos '.UserService::MIN_PASSWORD.' caracteres.'], ['password' => 'contraseña temporal']);
        $temporary = $this->users->resetPassword($user, $request->user(), $data['password'] ?? null);

        return $this->respond($request, $user->fresh(), 200, ['temporary_password' => $temporary]);
    }

    private function validated(Request $request, ?User $user): array
    {
        $request->merge([
            'username' => is_string($request->input('username')) ? mb_strtolower(trim($request->input('username'))) : $request->input('username'),
            'email' => blank($request->input('email')) ? null : mb_strtolower(trim((string) $request->input('email'))),
        ]);

        return $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'username' => ['required', 'string', 'regex:/^[a-z0-9.-]{3,40}$/', Rule::unique('users', 'username')->ignore($user?->id)],
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'role' => ['required', Rule::enum(Role::class)],
        ], [
            'username.regex' => 'Usa de 3 a 40 letras minúsculas, números, punto o guion.', 'username.unique' => 'Ese usuario ya existe.', 'email.unique' => 'Ese correo ya está en uso.',
        ], ['name' => 'nombre', 'username' => 'usuario', 'email' => 'correo', 'role' => 'rol']);
    }

    private function respond(Request $request, User $user, int $status = 200, array $extra = []): JsonResponse
    {
        return response()->json(['data' => $this->row($user, $request->user())] + $extra, $status)->header('Cache-Control', 'private, no-store');
    }

    private function row(User $user, User $viewer): array
    {
        return [
            'id' => $user->id, 'name' => $user->name, 'username' => $user->username, 'email' => $user->email,
            'role' => $user->role->value, 'role_label' => $user->role->label(), 'is_active' => $user->is_active, 'must_change_password' => $user->must_change_password,
            'last_login_at' => $user->last_login_at?->toIso8601String(), 'created_at' => $user->created_at?->toIso8601String(),
            'is_self' => $user->id === $viewer->id,
        ];
    }
}
