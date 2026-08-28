# Upgrading

## Untagged checkout → 0.1.0

**If your host still has `config/numerosis-billing.php` and/or
`config/numerosis-tenancy.php` published, delete both and re-publish
`numerosis-config`.**

Both files were removed in `numerosis@59f026f` (decision D13,
`.claude/plans/package-extraction.md`) — their contents moved into
`config/numerosis.php` under nested `'billing'` and `'tenancy'` keys. The
package's own `mergeConfigFrom()` for those two files was removed at the
same time, so a host holding a published copy of either loses its
customisations **silently**: nothing errors, the package falls back to its
own defaults under `numerosis.billing.*`/`numerosis.tenancy.*`, and the
orphaned file is simply never read again.

```bash
rm -f config/numerosis-billing.php config/numerosis-tenancy.php
php artisan vendor:publish --tag=numerosis-config --force
```

Then re-apply whatever customisations those two files held, into the
corresponding nested key of `config/numerosis.php`.

If your published `config/numerosis.php` predates this change too (i.e. it
has no top-level `'billing'`/`'tenancy'` keys at all), diff it against the
package's current `config/numerosis.php` and merge by hand — `--force`
overwrites the whole file.
