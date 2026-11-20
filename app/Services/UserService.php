<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Order;
use App\Models\User;
use App\Support\ActivityDiff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Rules of the panel's people. People are never deleted, only deactivated. Always at least one active administrator; nobody
 * deactivates themselves or drops their own admin role; a delivery person with pending deliveries is reassigned first.
 * Every change is one Historial row (module "users") in the same transaction; passwords never reach it.
 */
class UserService
{
    public const MIN_PASSWORD = 10;

    public function __construct(private readonly ActivityLogger $activity) {}

    /** @param  array{name: string, username: string, email?: ?string, role: string, password: string}  $data */
    public function create(array $data, User $by): User
    {
        return DB::transaction(function () use ($data, $by) {
            $user = new User;
            $user->forceFill([
                'name' => trim($data['name']), 'username' => $data['username'], 'email' => filled($data['email'] ?? null) ? $data['email'] : null,
                'role' => $data['role'], 'password' => $data['password'], 'is_active' => true, 'must_change_password' => true, 'created_by' => $by->id,
            ])->save();
            $this->activity->log('users', 'created', $user, ['name' => [null, $user->name], 'username' => [null, $user->username], 'role' => [null, $user->role->label()]], actor: $by);

            return $user;
        });
    }

    /** @param  array{name: string, username: string, email?: ?string, role: string}  $data */
    public function update(User $target, array $data, User $by): User
    {
        return DB::transaction(function () use ($target, $data, $by) {
            $target = User::query()->lockForUpdate()->findOrFail($target->id);
            $newRole = Role::from($data['role']);
            $roleChanged = $target->role !== $newRole;

            if ($roleChanged) {
                if ($target->id === $by->id) {
                    throw ValidationException::withMessages(['role' => ['No puedes cambiar tu propio rol.']]);
                }
                if ($target->role === Role::Admin) {
                    $this->assertAnotherAdminRemains($target);
                }
                if ($target->role === Role::Delivery) {
                    $this->assertNoPendingDeliveries($target);
                }
            }

            $target->fill(['name' => trim($data['name'])]);
            $target->forceFill(['username' => $data['username'], 'email' => filled($data['email'] ?? null) ? $data['email'] : null, 'role' => $newRole]);
            $changes = ActivityDiff::dirty($target, ['name', 'username', 'role']);
            if (isset($changes['role'])) {
                $changes['role'] = [Role::from($changes['role'][0])->label(), $newRole->label()];
            }
            $emailChanged = $target->isDirty('email');
            $target->save();
            if ($roleChanged) {
                $this->closeSessions($target); // the new role applies at once
            }
            if ($changes || $emailChanged) {
                // The email is personal data: the trail only says it changed.
                $this->activity->log('users', $roleChanged ? 'role_changed' : 'updated', $target, $changes, $emailChanged ? ['email' => 'actualizado'] : [], actor: $by);
            }

            return $target;
        });
    }

    public function deactivate(User $target, User $by): User
    {
        return DB::transaction(function () use ($target, $by) {
            $target = User::query()->lockForUpdate()->findOrFail($target->id);
            if ($target->id === $by->id) {
                throw ValidationException::withMessages(['user' => ['No puedes desactivarte a ti mismo.']]);
            }
            if (! $target->is_active) {
                return $target;
            }
            if ($target->role === Role::Admin) {
                $this->assertAnotherAdminRemains($target);
            }
            if ($target->role === Role::Delivery) {
                $this->assertNoPendingDeliveries($target);
            }
            $target->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();
            $this->closeSessions($target);
            $this->activity->log('users', 'deactivated', $target, ['is_active' => ['Sí', 'No']], actor: $by);

            return $target;
        });
    }

    public function reactivate(User $target, User $by): User
    {
        return DB::transaction(function () use ($target, $by) {
            $target = User::query()->lockForUpdate()->findOrFail($target->id);
            if ($target->is_active) {
                return $target;
            }
            $target->forceFill(['is_active' => true, 'deactivated_at' => null])->save();
            $this->activity->log('users', 'reactivated', $target, ['is_active' => ['No', 'Sí']], actor: $by);

            return $target;
        });
    }

    /** Sets a temporary password (given or generated). The person must change it on the next sign-in. Returns it so it is shown once. */
    public function resetPassword(User $target, User $by, ?string $password = null): string
    {
        if ($target->id === $by->id) {
            throw ValidationException::withMessages(['user' => ['No puedes restablecer tu propia contraseña desde aquí.']]);
        }
        $temporary = $password ?: $this->generatePassword();

        DB::transaction(function () use ($target, $by, $temporary) {
            $target = User::query()->lockForUpdate()->findOrFail($target->id);
            $target->forceFill(['password' => $temporary, 'must_change_password' => true])->save();
            $this->closeSessions($target);
            $this->activity->log('users', 'password_reset', $target, [], actor: $by); // never the password
        });

        return $temporary;
    }

    /** The person replaces a temporary password (the only time they change it themselves). Their other sessions end. */
    public function changeOwnPassword(User $user, string $password): User
    {
        if (! $user->must_change_password) {
            throw ValidationException::withMessages(['password' => ['No tienes una contraseña temporal pendiente.']]);
        }

        return DB::transaction(function () use ($user, $password) {
            $user->forceFill(['password' => $password, 'must_change_password' => false])->save();
            $this->closeSessions($user);
            $this->activity->log('users', 'password_changed', $user, [], actor: $user);

            return $user;
        });
    }

    public function generatePassword(): string
    {
        return Str::password(14, symbols: false);
    }

    /** Bumps the version every open session carries: they all stop working on the next request. */
    public function closeSessions(User $user): void
    {
        $user->forceFill(['session_version' => $user->session_version + 1])->save();
    }

    /** At least one active administrator must remain (the rows are locked, so two requests cannot both remove the last). */
    private function assertAnotherAdminRemains(User $target): void
    {
        $others = User::query()->where('role', Role::Admin->value)->where('is_active', true)->where('id', '<>', $target->id)->lockForUpdate()->count();
        if ($others === 0) {
            throw ValidationException::withMessages(['user' => ['Debe quedar al menos un administrador activo.']]);
        }
    }

    /** @return list<string> references of the shipped orders still assigned to the person */
    public function pendingDeliveries(User $user): array
    {
        return Order::query()->where('assigned_delivery_user_id', $user->id)->where('status', 'shipped')->orderBy('id')->pluck('reference')->all();
    }

    private function assertNoPendingDeliveries(User $target): void
    {
        $pending = $this->pendingDeliveries($target);
        if ($pending) {
            throw ValidationException::withMessages(['user' => ['Tiene entregas pendientes: '.implode(', ', $pending).'. Reasígnalas antes de continuar.']]);
        }
    }
}
