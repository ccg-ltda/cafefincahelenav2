<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The public address of the site (APP_URL) is a local one (localhost, loopback or a private IP). Wompi's firewall answers
 * "403 Request blocked" to a checkout whose redirect-url points there, so the attempt is refused BEFORE sending the customer to Wompi.
 * It cannot happen in production (APP_URL is the public domain): it exists to give whoever tests locally a message that explains it.
 */
class WompiRedirectUrlException extends RuntimeException
{
    public const CODE = 'invalid_redirect_url';

    public function __construct()
    {
        parent::__construct('La dirección pública del sitio (APP_URL) es una dirección local (localhost o una IP privada) y Wompi bloquea esos pagos. '
            .'Configura APP_URL con la dirección pública https del sitio (en pruebas, la de tu túnel) y ejecuta php artisan config:clear.');
    }
}
