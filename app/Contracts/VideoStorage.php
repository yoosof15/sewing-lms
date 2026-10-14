<?php

namespace App\Contracts;

interface VideoStorage
{
    public function createTemporary(string $key): void;

    public function append(string $key, string $bytes, int $expectedOffset): int;

    public function temporarySize(string $key): int;

    public function detectedMimeType(string $key): ?string;

    public function promote(string $temporaryKey, string $storageKey): void;

    public function temporaryExists(string $key): bool;

    public function exists(string $key): bool;

    public function size(string $key): int;

    public function deleteTemporary(string $key): void;

    public function delete(string $key): void;

    public function localPathForProcessing(string $key): string;

    public function storeProcessed(string $localPath, string $storageKey): void;

    public function driver(): string;
}
