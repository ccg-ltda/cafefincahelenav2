<?php

namespace App\Enums;

/** The roles of the admin panel. What each one can do lives in App\Support\Permissions (one place). */
enum Role: string
{
    case Admin = 'admin';
    case Dispatch = 'dispatch';
    case Delivery = 'delivery';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Dispatch => 'Despacho y entrega',
            self::Delivery => 'Solo entrega',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Acceso total: catálogo, pedidos, clientes, usuarios e historial.',
            self::Dispatch => 'Gestiona los pedidos activos: preparar, enviar y entregar. Sin precios ni correos de clientes.',
            self::Delivery => 'Solo ve sus entregas asignadas y las marca como entregadas.',
        };
    }

    /** Where the person lands after logging in. */
    public function home(): string
    {
        return match ($this) {
            self::Admin => '/admin/today',
            self::Dispatch => '/admin/today',
            self::Delivery => '/admin/today',
        };
    }
}
