<?php

namespace App\Services;

use App\Exceptions\WompiRedirectUrlException;
use App\Models\Order;
use App\Models\Payment;

/**
 * Everything about Wompi that does not talk to the network: whether the keys are usable, the integrity signature, the Web
 * Checkout URL, the webhook checksum and the status mapping. The secrets are read here, on the server, and never leave it:
 * the browser only receives the finished checkout URL (public key + signature, never the secret).
 *
 * Source: docs.wompi.co/docs/colombia/widget-checkout-web (Web Checkout parameters and integrity signature),
 * /eventos (checksum) and /ambientes-y-llaves (key prefixes and environments).
 */
class WompiService
{
    public const PROVIDER = 'wompi';

    private const CHECKOUT_URL = 'https://checkout.wompi.co/p/';

    /** Key prefixes per environment (ambientes-y-llaves). */
    private const PREFIXES = [
        'sandbox' => ['public_key' => 'pub_test_', 'private_key' => 'prv_test_', 'integrity_secret' => 'test_integrity_', 'events_secret' => 'test_events_'],
        'production' => ['public_key' => 'pub_prod_', 'private_key' => 'prv_prod_', 'integrity_secret' => 'prod_integrity_', 'events_secret' => 'prod_events_'],
    ];

    /** Payments are usable only with every key present and each one belonging to the configured environment. */
    public function enabled(): bool
    {
        $environment = $this->environment();
        if (! isset(self::PREFIXES[$environment])) {
            return false;
        }
        foreach (self::PREFIXES[$environment] as $key => $prefix) {
            $value = (string) config("services.wompi.$key");
            if ($value === '' || ! str_starts_with($value, $prefix)) {
                return false;
            }
        }

        return true;
    }

    public function environment(): string
    {
        return (string) config('services.wompi.env');
    }

    public function baseUrl(): string
    {
        return $this->environment() === 'production' ? 'https://production.wompi.co/v1' : 'https://sandbox.wompi.co/v1';
    }

    /** SHA256 hex of `<reference><amount in cents><currency>[<expiration>]<integrity secret>`. */
    public function integritySignature(string $reference, int $amountInCents, string $currency, ?string $expiration = null): string
    {
        return hash('sha256', $reference.$amountInCents.$currency.($expiration ?? '').config('services.wompi.integrity_secret'));
    }

    /** The most characters of an address line sent to Wompi (the customer can complete it there). */
    private const ADDRESS_LINE_MAX = 120;

    /** Wompi's firewall answers 403 to very long checkout URLs (observed from ~3.5 k characters); beyond this the optional line is left out. */
    private const URL_MAX = 1800;

    /**
     * Where Wompi sends the customer back: APP_URL + /pago/resultado (never the Host of the request, which anybody can forge). A
     * local address (localhost, loopback, a private IP) is refused with a clear message: Wompi's firewall blocks the checkout
     * with a "403 Request blocked" when the redirect-url points there. Any public domain (http or https) passes.
     */
    public function redirectUrl(): string
    {
        $url = rtrim((string) config('app.url'), '/').'/pago/resultado';
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        $local = $host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')
            || (filter_var($host, FILTER_VALIDATE_IP) !== false && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false);
        if ($local) {
            throw new WompiRedirectUrlException;
        }

        return $url;
    }

    /**
     * The Web Checkout URL of one attempt. A storefront order carries the customer's data so they do not type it again; a MANUAL
     * order's link (sent by WhatsApp, easy to forward) only carries what Wompi needs to charge, plus the email when there is one.
     */
    public function checkoutUrl(Payment $payment, Order $order): string
    {
        $cents = (int) $payment->amount_cop * 100;
        $expiration = $payment->checkout_expires_at?->copy()->utc()->format('Y-m-d\TH:i:s.000\Z');
        $line = fn (?string $text) => trim(mb_substr(trim((string) $text), 0, self::ADDRESS_LINE_MAX));

        $params = [
            'public-key' => config('services.wompi.public_key'),
            'currency' => $payment->currency,
            'amount-in-cents' => $cents,
            'reference' => $payment->reference,
            'signature:integrity' => $this->integritySignature($payment->reference, $cents, $payment->currency, $expiration),
            'redirect-url' => $this->redirectUrl(),
            'customer-data:email' => $order->customer_email,
        ];
        if ($order->channel !== 'manual') {
            $params += [
                'customer-data:full-name' => $order->customer_name,
                'customer-data:phone-number' => $order->customer_phone,
                'customer-data:phone-number-prefix' => '+57',
                'shipping-address:address-line-1' => $line($order->delivery_address),
                'shipping-address:country' => 'CO',
                'shipping-address:city' => $order->delivery_city,
                'shipping-address:region' => $order->delivery_department,
                'shipping-address:phone-number' => $order->customer_phone,
                'shipping-address:name' => $order->customer_name,
            ];
        }
        if ($expiration) {
            $params['expiration-time'] = $expiration;
        }
        $url = fn (array $with) => self::CHECKOUT_URL.'?'.http_build_query($with, '', '&', PHP_QUERY_RFC3986);

        // The optional "address-line-2" (delivery details) is the first thing to go when the URL gets too long: it is only a convenience.
        // A manual order's link carries no personal data except the email (when there is one): it may be forwarded.
        if ($order->channel !== 'manual' && filled($order->delivery_details) && $line($order->delivery_details) !== '') {
            $withDetails = $params + ['shipping-address:address-line-2' => $line($order->delivery_details)];
            if (strlen($url($withDetails)) <= self::URL_MAX) {
                return $url($withDetails);
            }
        }

        return $url($params);
    }

    /**
     * Checksum of a webhook (eventos): SHA256 of the values of `signature.properties` read from `data` in that order (the
     * list is never hard-coded: Wompi says it can change), then `timestamp`, then the events secret.
     */
    public function eventChecksum(array $payload): ?string
    {
        $properties = data_get($payload, 'signature.properties');
        $timestamp = data_get($payload, 'timestamp');
        if (! is_array($properties) || $properties === [] || $timestamp === null) {
            return null;
        }
        $concatenated = '';
        foreach ($properties as $property) {
            $value = data_get($payload['data'] ?? [], $property);
            if (! is_scalar($value)) {
                return null;
            }
            $concatenated .= $value;
        }

        return hash('sha256', $concatenated.$timestamp.config('services.wompi.events_secret'));
    }

    /** Constant-time, case-insensitive (Wompi documents the checksum in uppercase hex). */
    public function validEvent(array $payload, ?string $header = null): bool
    {
        $received = (string) data_get($payload, 'signature.checksum');
        $expected = $this->eventChecksum($payload);
        if ($expected === null || $received === '' || ! hash_equals($expected, strtolower($received))) {
            return false;
        }

        return $header === null || $header === '' || hash_equals($expected, strtolower($header));
    }

    /** Wompi status -> status stored in `payments.status`; null for a status this code does not know. */
    public function mapStatus(?string $status): ?string
    {
        return ['PENDING' => 'pending', 'APPROVED' => 'approved', 'DECLINED' => 'declined', 'VOIDED' => 'voided', 'ERROR' => 'error'][strtoupper((string) $status)] ?? null;
    }
}
