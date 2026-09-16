<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

/**
 * Encryption at rest for a backup artefact, which holds everything a tenant
 * has. File in, file out: an artefact is the size of a tenant database, so
 * nothing here takes or returns its contents as a string.
 */
interface EncryptsArtifacts
{
    public function encrypt(string $plainFile, string $cipherFile): void;

    public function decrypt(string $cipherFile, string $plainFile): void;
}
