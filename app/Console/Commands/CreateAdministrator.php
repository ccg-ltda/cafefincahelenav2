<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

class CreateAdministrator extends Command
{
    protected $signature = 'admin:create';

    protected $description = 'Crear un administrador autorizado de forma interactiva';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Este comando requiere una terminal interactiva.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Nombre completo'));
        $username = mb_strtolower(trim((string) $this->ask('Usuario (minúsculas, números, punto o guion)')));
        $email = trim((string) $this->ask('Correo electrónico (opcional)'));
        $password = $this->secret('Contraseña');
        $confirmation = $this->secret('Confirmar contraseña');

        $validator = Validator::make([
            'name' => $name,
            'username' => $username,
            'email' => $email === '' ? null : $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'regex:/^[a-z0-9.-]{3,40}$/', 'unique:users,username'],
            'email' => ['nullable', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        try {
            $user = new User;
            $user->name = $name;
            $user->username = $username;
            $user->email = $email === '' ? null : $email;
            // The model's hashed cast applies Laravel's configured password hash.
            $user->password = $password;
            $user->role = Role::Admin;
            $user->is_active = true;
            $user->save();
        } catch (Throwable) {
            $this->error('No fue posible crear el administrador. Comprueba la conexión y que el usuario o el correo no existan.');

            return self::FAILURE;
        }

        $this->info('Administrador creado correctamente.');

        return self::SUCCESS;
    }
}
