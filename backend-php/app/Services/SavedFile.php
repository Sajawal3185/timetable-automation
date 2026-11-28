<?php

namespace App\Services;

/** What FileStorage::save() returns (Python's SavedFile dataclass). */
final class SavedFile
{
    public function __construct(
        public readonly string $relativePath,
        public readonly string $originalFilename,
        public readonly ?string $contentType,
        public readonly int $size,
    ) {}
}
