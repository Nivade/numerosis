<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Auth;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Nvade\Numerosis\Contracts\Auth\ExportsPersonalData;
use Nvade\Numerosis\Contracts\Tenancy\ExportsTenantData;
use Nvade\Numerosis\Exceptions\Tenancy\TenantBackupFailed;
use Nvade\Numerosis\Jobs\Concerns\WritesDataExportOutcome;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Consent;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\SocialAccount;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;
use ZipArchive;

/**
 * Wraps {@see ExportsTenantData}, once per tenant the subject belongs to, and
 * adds their central identity rows. The tenant exporter is already narrowed to
 * one person, so nothing here filters twice.
 *
 * A JSON dump with no explanation does not answer an access request in
 * practice, so the archive leads with a written manifest.
 */
class PersonalDataExporter implements ExportsPersonalData
{
    use WritesDataExportOutcome;

    public function __construct(private readonly ExportsTenantData $tenantExporter) {}

    public function export(string $globalUserId, ?string $disk = null): string
    {
        $user = Numerosis::model(CentralUser::class)::query()->where('global_id', $globalUserId)->firstOrFail();

        if (! $user instanceof CentralUser) {
            throw TenantBackupFailed::unreadable($globalUserId);
        }

        $diskName = $disk ?? $this->exportDisk();
        $storage = Storage::disk($diskName);

        $archivePath = (string) tempnam(sys_get_temp_dir(), 'numerosis-personal');

        $zip = new ZipArchive;

        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw TenantBackupFailed::unwritable($archivePath);
        }

        try {
            $tenants = $this->tenantsFor($user);

            $zip->addFromString('README.md', $this->manifest($user, $tenants));
            $zip->addFromString('account/profile.json', $this->profile($user));
            $zip->addFromString('account/social-accounts.jsonl', $this->lines($this->socialAccounts($user)));
            $zip->addFromString('account/memberships.jsonl', $this->lines($this->memberships($user)));
            $zip->addFromString('account/consents.jsonl', $this->lines($this->consents($user)));

            foreach ($tenants as $tenant) {
                $this->addTenantArchive($zip, $tenant, (string) $user->global_id, $diskName);
            }

            $zip->close();

            return $this->store($storage, $user, $archivePath);
        } finally {
            @unlink($archivePath);
        }
    }

    /**
     * Copied entry by entry instead of embedded whole: a zip inside a zip is
     * one more thing the person receiving it has to work out.
     */
    private function addTenantArchive(ZipArchive $zip, Tenant $tenant, string $globalId, string $diskName): void
    {
        $path = $this->tenantExporter->export($tenant, $globalId, $diskName);
        $storage = Storage::disk($diskName);

        $local = (string) tempnam(sys_get_temp_dir(), 'numerosis-tenant-zip');

        try {
            file_put_contents($local, $storage->get($path) ?? '');

            $inner = new ZipArchive;

            // A partial archive that looks complete answers a subject access
            // request wrongly, so the whole export fails instead.
            if ($inner->open($local) !== true) {
                throw TenantBackupFailed::unreadable($path);
            }

            for ($index = 0; $index < $inner->numFiles; $index++) {
                $name = (string) $inner->getNameIndex($index);
                $contents = $inner->getFromIndex($index);

                if (is_string($contents)) {
                    $zip->addFromString('workspaces/'.$tenant->id.'/'.$name, $contents);
                }
            }

            $inner->close();
        } finally {
            @unlink($local);
            $storage->delete($path);
        }
    }

    /**
     * @return list<Tenant>
     */
    private function tenantsFor(CentralUser $user): array
    {
        /** @var list<Tenant> $tenants */
        $tenants = $user->tenants()->get()->all();

        return $tenants;
    }

    /**
     * @param  list<Tenant>  $tenants
     */
    private function manifest(CentralUser $user, array $tenants): string
    {
        $workspaces = $tenants === []
            ? "You are not a member of any workspace.\n"
            : implode('', array_map(
                static fn (Tenant $tenant): string => "- `workspaces/{$tenant->id}/` — ".$tenant->name."\n",
                $tenants
            ));

        return <<<MARKDOWN
            # Your data

            Produced on {$this->now()} for {$user->email}.

            ## What is in here

            - `account/profile.json` — the name, address and dates held on your account.
            - `account/social-accounts.jsonl` — the third-party logins you connected.
            - `account/memberships.jsonl` — the workspaces you belong to and your role in each.
            - `account/consents.jsonl` — what you agreed to, and when.

            {$workspaces}
            Each workspace folder holds the rows that name you inside that workspace,
            one JSON object per line (`.jsonl`), plus a `manifest.json` describing it.

            ## What is not in here

            Content created by other people, and rows that belong to a workspace rather
            than to you, are that workspace's data and are not included.

            Billing and invoice records are kept under their own statutory retention
            period and are not part of an access request.

            ## Your password

            Never stored in a readable form and never exported.
            MARKDOWN;
    }

    private function profile(CentralUser $user): string
    {
        return json_encode([
            'global_id' => $user->global_id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'created_at' => $user->freshTimestampString(),
            'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function socialAccounts(CentralUser $user): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = Numerosis::model(SocialAccount::class)::query()
            ->where('user_id', $user->getKey())
            ->get(['provider', 'provider_id', 'name', 'email', 'created_at'])
            ->map(fn ($account): array => $account->toArray())
            ->all();

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function memberships(CentralUser $user): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = Numerosis::model(Membership::class)::query()
            ->where('global_user_id', $user->global_id)
            ->get(['tenant_id', 'role', 'invited_at', 'joined_at'])
            ->map(fn ($membership): array => $membership->toArray())
            ->all();

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function consents(CentralUser $user): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = Numerosis::model(Consent::class)::query()
            ->where('global_user_id', $user->global_id)
            ->get(['purpose', 'terms_version', 'ip_address', 'granted_at'])
            ->map(fn ($consent): array => $consent->toArray())
            ->all();

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function lines(array $rows): string
    {
        return implode('', array_map(
            static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)."\n",
            $rows
        ));
    }

    private function store(Filesystem $storage, CentralUser $user, string $archivePath): string
    {
        $path = sprintf('personal-exports/%s/%s.zip', $user->global_id, now()->format('Y-m-d-His'));

        $handle = fopen($archivePath, 'rb');

        if ($handle === false) {
            throw TenantBackupFailed::unreadable($archivePath);
        }

        try {
            $storage->writeStream($path, $handle);
        } finally {
            fclose($handle);
        }

        return $path;
    }

    private function now(): string
    {
        return now()->toDayDateTimeString();
    }
}
