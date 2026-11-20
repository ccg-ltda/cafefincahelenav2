<?php

namespace App\Support;

use App\Enums\Role;

/**
 * The ONLY place that says which role has which permission. Gates are registered from this matrix (AppServiceProvider),
 * routes use `can:<permission>`, /admin/session sends the list to the frontend and OrderPresenter hides fields with it.
 */
final class Permissions
{
    public const ALL = [
        'dashboard.view',
        'today.view', 'orders.list', 'orders.view_all', 'orders.view_assigned', 'orders.view_financials', 'orders.view_customer_email',
        'orders.prepare', 'orders.ship', 'orders.deliver', 'orders.cancel', 'orders.print',
        'inventory.edit',
        'products.manage', 'promotions.manage', 'coupons.manage',
        'customers.view', 'customers.manage',
        'whatsapp.manage', 'activity.view', 'users.manage',
        'payments.refresh', 'payments.review', // admin only: query Wompi for an order / mark a payment alert as reviewed (never "mark as paid")
        'orders.create_manual', // admin only: register an order that came by WhatsApp or phone (it is always paid through Wompi)
        'emails.manage', // admin only: see and resend the emails of an order, preview the templates
    ];

    private const DISPATCH = ['today.view', 'orders.list', 'orders.view_all', 'orders.prepare', 'orders.ship', 'orders.deliver', 'orders.print'];

    private const DELIVERY = ['today.view', 'orders.list', 'orders.view_assigned', 'orders.deliver'];

    /** @return list<string> */
    public static function for(Role|string|null $role): array
    {
        $role = $role instanceof Role ? $role : Role::tryFrom((string) $role);

        return match ($role) {
            Role::Admin => self::ALL, // includes orders.cancel: defined, not implemented yet (order management module)
            Role::Dispatch => self::DISPATCH,
            Role::Delivery => self::DELIVERY,
            default => [],
        };
    }

    public static function has(Role|string|null $role, string $permission): bool
    {
        return in_array($permission, self::for($role), true);
    }
}
