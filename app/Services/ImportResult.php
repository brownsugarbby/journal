<?php

namespace App\Services;

/**
 * What an Excel import did. $fileSaldo is the SALDO on the file's latest
 * row (PGA export), to compare with the book balance after the import.
 */
final class ImportResult
{
    public function __construct(
        public readonly int $imported,
        public readonly int $skipped,
        public readonly ?string $fileSaldo = null,
    ) {}
}
