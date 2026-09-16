<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Support;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Exclusive ownership of the host route files every parallel worker shares.
 *
 * `base_path()` is the one Testbench skeleton inside `vendor/`, so a test
 * writing `routes/tenant.php` writes it for all eight workers at once. Another
 * worker booting at that moment loads routes this test wrote, and a worker
 * deleting it between `RouteLoader`'s `is_file()` and its `require` aborts an
 * unrelated test with a missing-file error.
 */
final class HostRouteFiles
{
    private const array FILES = ['web.php', 'tenant.php', 'api.php'];

    /** @var resource|null */
    private static $lock;

    /** Blocks until whichever worker owns the files has released them. */
    public static function acquire(): void
    {
        if (self::$lock !== null) {
            return;
        }

        $handle = fopen(self::lockPath(), 'c');

        if ($handle === false) {
            throw new RuntimeException('Could not open '.self::lockPath().' to serialise host route files.');
        }

        flock($handle, LOCK_EX);

        self::$lock = $handle;
    }

    public static function write(string $file, string $body): void
    {
        self::acquire();

        File::ensureDirectoryExists(base_path('routes'));
        File::put(base_path('routes/'.$file), $body);
    }

    /** Deletes all three files, so a test only has to release what it took. */
    public static function release(): void
    {
        foreach (self::FILES as $file) {
            File::delete(base_path('routes/'.$file));
        }

        if (self::$lock === null) {
            return;
        }

        flock(self::$lock, LOCK_UN);
        fclose(self::$lock);

        self::$lock = null;
    }

    /**
     * Outside `routes/`, so the lock file is not something `RouteLoader` could
     * pick up, and keyed by the skeleton it guards rather than shared with
     * another checkout's workers.
     */
    private static function lockPath(): string
    {
        return sys_get_temp_dir().'/numerosis-host-routes-'.md5(base_path()).'.lock';
    }
}
