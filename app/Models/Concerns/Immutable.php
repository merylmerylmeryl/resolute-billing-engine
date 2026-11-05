<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * Makes a model append-only: it can be created, but never updated or deleted.
 *
 * This guards Eloquent model operations only. Query-builder updates and deletes,
 * and the "quietly" methods, skip model events, so they are not blocked here.
 * Foreign keys stop a delete only when other rows depend on the record.
 */
trait Immutable
{
    public static function bootImmutable(): void
    {
        static::updating(fn () => throw new LogicException(static::class.' records are immutable and cannot be updated.'));
        static::deleting(fn () => throw new LogicException(static::class.' records are immutable and cannot be deleted.'));
    }
}
