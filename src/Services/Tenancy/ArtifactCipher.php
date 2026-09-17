<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Tenancy;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Nvade\Numerosis\Contracts\Tenancy\EncryptsArtifacts;
use Nvade\Numerosis\Exceptions\Tenancy\TenantBackupFailed;

/**
 * Encrypts a backup artefact a block at a time with libsodium's secretstream,
 * which authenticates each block and marks the end of the stream, so a
 * truncated artefact fails to decrypt instead of restoring a partial database.
 * `Crypt::encryptString()` would hold the whole tenant database in memory.
 */
class ArtifactCipher implements EncryptsArtifacts
{
    private const int BLOCK = 1_048_576;

    /** `SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES`, which PHPStan will not take as a length. */
    private const int KEY_BYTES = 32;

    public function encrypt(string $plainFile, string $cipherFile): void
    {
        $in = $this->open($plainFile, 'rb');
        $out = $this->open($cipherFile, 'wb');

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key());

            fwrite($out, $header);

            while (! feof($in)) {
                $block = fread($in, self::BLOCK);

                if ($block === false || $block === '') {
                    continue;
                }

                fwrite($out, sodium_crypto_secretstream_xchacha20poly1305_push($state, $block));
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    public function decrypt(string $cipherFile, string $plainFile): void
    {
        $in = $this->open($cipherFile, 'rb');
        $out = $this->open($plainFile, 'wb');

        try {
            $header = (string) fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->key());

            while (! feof($in)) {
                $block = fread($in, self::BLOCK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);

                if ($block === false || $block === '') {
                    continue;
                }

                $decrypted = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $block);

                if ($decrypted === false) {
                    throw TenantBackupFailed::unreadable($cipherFile);
                }

                fwrite($out, $decrypted[0]);
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * @return resource
     */
    private function open(string $file, string $mode): mixed
    {
        $handle = fopen($file, $mode);

        if ($handle === false) {
            throw str_contains($mode, 'r')
                ? TenantBackupFailed::unreadable($file)
                : TenantBackupFailed::unwritable($file);
        }

        return $handle;
    }

    /** The app key, which is 32 bytes once its `base64:` prefix is decoded. */
    private function key(): string
    {
        $key = Config::string('app.key');

        if (Str::startsWith($key, 'base64:')) {
            $key = (string) base64_decode(Str::after($key, 'base64:'), true);
        }

        return str_pad(substr($key, 0, self::KEY_BYTES), self::KEY_BYTES, "\0");
    }
}
