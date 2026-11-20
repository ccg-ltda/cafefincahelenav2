<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Customers are identified by their normalized email. Order counts, spending and first/last purchase are never stored:
 * CustomerListing computes them from `orders` (see Order::scopeValid).
 */
class CustomerService
{
    public function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Creates the customer of an order or refreshes the existing one with the data of this (newest) order.
     * Call it inside the order transaction. `$optIn` is the marketing box ticked in this order: it records the consent
     * (re-activating an earlier opt-out); an unticked box never erases a previous consent.
     *
     * @param  array{customer_email?: ?string, customer_name: string, customer_phone: string, delivery_city: string, delivery_department?: ?string, delivery_address: string, delivery_details?: ?string}  $data
     */
    public function syncFromOrder(array $data, bool $optIn): Customer
    {
        // The storefront always sends an email; a manual order may not (the customer only has a phone).
        $email = filled($data['customer_email'] ?? null) ? $this->normalizeEmail($data['customer_email']) : null;
        $profile = [
            'name' => $data['customer_name'], 'phone' => $data['customer_phone'], 'city' => $data['delivery_city'],
            'department' => $data['delivery_department'] ?? null, 'last_address' => $data['delivery_address'], 'last_address_details' => $data['delivery_details'] ?? null,
        ];

        $customer = $email !== null ? Customer::query()->where('email', $email)->lockForUpdate()->first() : null;
        if (! $customer) {
            // Without an email the customer is identified by phone among those that have none; with an email that nobody has yet,
            // a customer that had only a phone is completed instead of duplicated.
            $byPhone = Customer::query()->whereNull('email')->where('phone', $data['customer_phone'])->orderBy('id')->lockForUpdate()->first();
            if ($byPhone) {
                $customer = $byPhone;
                $email !== null && $profile['email'] = $email;
            }
        }

        if (! $customer) {
            try {
                $customer = Customer::create(['email' => $email] + $profile);
            } catch (QueryException $error) {
                // A concurrent order created the same customer first: use it.
                if ((string) $error->getCode() !== '23000' || $email === null || ! ($customer = Customer::query()->where('email', $email)->first())) {
                    throw $error;
                }
                $customer->update($profile);
            }
        } else {
            $customer->update($profile);
        }

        if ($optIn) {
            $customer->update(['marketing_opt_in_at' => now()]);
        }

        return $customer;
    }

    /** The customer asked not to receive promotions. It wins over the opt-in until a new purchase ticks the box again. */
    public function optOut(Customer $customer): Customer
    {
        if ($customer->acceptsMarketing()) {
            DB::transaction(function () use ($customer) {
                $customer->update(['marketing_opt_out_at' => now()]);
                // No personal data in the row: the label already carries the masked email.
                app(ActivityLogger::class)->log('customers', 'marketing_opted_out', $customer);
            });
        }

        return $customer;
    }

    /**
     * Creates the customers of existing orders and links them. Idempotent: orders are visited oldest first (so the profile
     * ends up with the newest order's data), customers are matched by email and already linked orders are skipped.
     *
     * @return array{orders: int, linked: int, customers_created: int, customers_total: int}
     */
    public function backfill(bool $dryRun = false): array
    {
        $pending = Order::query()->whereNull('customer_id')->whereNotNull('customer_email')->orderBy('created_at')->orderBy('id')->get();
        $existing = Customer::query()->pluck('id', 'email')->all();
        $new = [];

        foreach ($pending as $order) {
            $email = $this->normalizeEmail($order->customer_email);
            if (! isset($existing[$email])) {
                $new[$email] = true;
            }
            if ($dryRun) {
                continue;
            }
            $customer = $this->syncFromOrder([
                'customer_email' => $email, 'customer_name' => $order->customer_name, 'customer_phone' => $order->customer_phone, 'delivery_city' => $order->delivery_city,
                'delivery_department' => $order->delivery_department, 'delivery_address' => $order->delivery_address, 'delivery_details' => $order->delivery_details,
            ], (bool) $order->marketing_opt_in);
            $existing[$email] = $customer->id;
            $order->update(['customer_id' => $customer->id]);
        }

        return [
            'orders' => $pending->count(), 'linked' => $dryRun ? 0 : $pending->count(), 'customers_created' => count($new),
            'customers_total' => count($existing) + ($dryRun ? count($new) : 0),
        ];
    }
}
