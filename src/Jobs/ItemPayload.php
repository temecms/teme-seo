<?php

/**
 * Reads what an editor panel's action queued: the item's ID and the form's
 * values, refusing a run that names no item.
 *
 * @package teme/seo
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Seo\Jobs;

use RuntimeException;
use Teme\Contracts\Jobs\JobContext;

final class ItemPayload
{
    /**
     * Returns the item's ID the run is about.
     *
     * @throws RuntimeException when the payload names no item
     */
    public static function itemId(JobContext $context): string
    {
        $itemId = $context->payload()['itemId'] ?? null;

        if (! is_string($itemId) || preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/D', $itemId) !== 1) {
            throw new RuntimeException(__('This run doesn’t say which item to work on.'));
        }

        return $itemId;
    }

    /**
     * Returns one of the form's values as text, empty when it wasn't given.
     */
    public static function value(JobContext $context, string $key): string
    {
        $values = $context->payload()['values'] ?? [];
        $value = is_array($values) ? ($values[$key] ?? '') : '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
