<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Roles and sign-in data for the panel users, delivery data for orders and the order status history.
     * `users.is_admin` is kept on purpose (nothing reads it any more): a later migration removes it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('admin')->after('password'); // default admin: the people who exist today are administrators
            $table->string('username', 40)->nullable()->after('name');
            $table->string('email')->nullable()->change();
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->unsignedInteger('session_version')->default(1); // bumped to close every open session of the person at once
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });

        $taken = [];
        foreach (DB::table('users')->orderBy('id')->get(['id', 'name', 'email', 'is_admin']) as $user) {
            $username = $this->username($user->name, $user->email, $taken);
            $taken[$username] = true;
            $values = ['username' => $username];
            if (! $user->is_admin) {
                // Someone without admin rights must not gain access: kept, deactivated and with the least privileged role.
                $values += ['role' => 'delivery', 'is_active' => false, 'deactivated_at' => now()];
                echo "  [aviso] usuario sin permisos de admin desactivado: #{$user->id} {$user->name}\n";
            }
            DB::table('users')->where('id', $user->id)->update($values);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('username');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('assigned_delivery_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('carrier', 100)->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('delivery_note')->nullable();
            $table->index(['assigned_delivery_user_id', 'status']);
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['assigned_delivery_user_id', 'status']);
            $table->dropConstrainedForeignId('assigned_delivery_user_id');
            $table->dropColumn(['carrier', 'tracking_number', 'confirmed_at', 'shipped_at', 'delivered_at', 'delivery_note']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropConstrainedForeignId('created_by');
            // `email` stays nullable: making it required again could fail if someone was created without one.
            $table->dropColumn(['role', 'username', 'is_active', 'must_change_password', 'session_version', 'last_login_at', 'deactivated_at']);
        });
    }

    /** "Alejandro Amado" becomes alejandro.amado; with a long name, first and last word. Falls back to the email's local part. */
    private function username(?string $name, ?string $email, array $taken): string
    {
        $words = preg_split('/\s+/', trim(Str::lower(Str::ascii((string) $name))), -1, PREG_SPLIT_NO_EMPTY);
        $base = count($words) > 1 ? $words[0].'.'.end($words) : ($words[0] ?? '');
        $base = trim(preg_replace('/[^a-z0-9.-]+/', '', $base), '.-');
        if ($base === '') {
            $base = trim(preg_replace('/[^a-z0-9.-]+/', '', Str::lower(Str::before((string) $email, '@'))), '.-') ?: 'usuario';
        }
        $base = substr($base, 0, 34);
        $username = $base;
        for ($number = 2; isset($taken[$username]); $number++) {
            $username = $base.$number;
        }

        return $username;
    }
};
