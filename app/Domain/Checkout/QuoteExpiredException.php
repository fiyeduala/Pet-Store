<?php

declare(strict_types=1);

namespace App\Domain\Checkout;

use RuntimeException;

/**
 * The shipping quote the shopper was shown is no longer valid. Checkout
 * must re-quote and show the new figures rather than honour a stale one
 * or silently charge the new price.
 */
class QuoteExpiredException extends RuntimeException {}
