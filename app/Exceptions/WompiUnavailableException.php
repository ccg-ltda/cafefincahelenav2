<?php

namespace App\Exceptions;

use RuntimeException;

/** Wompi could not be asked (network error, 5xx, rate limit or wrong keys). It never means "the payment does not exist". */
class WompiUnavailableException extends RuntimeException {}
