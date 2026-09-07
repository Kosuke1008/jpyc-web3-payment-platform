<?php

namespace App\Console\Commands;

use App\Payments\PaymentMigrationAuditService;
use Illuminate\Console\Command;
use JsonException;

final class AuditPaymentsForMainnetMigration extends Command
{
    protected $signature = 'payments:audit-mainnet-migration
        {--manifest= : Trusted Kairos historical snapshot manifest JSON}
        {--apply : Apply eligible manifest backfills}';

    protected $description = 'Audit legacy Payments and optionally apply explicit trusted Kairos snapshot backfills';

    public function handle(PaymentMigrationAuditService $audit): int
    {
        $manifest = $this->manifest();

        if ($manifest === null) {
            return self::FAILURE;
        }

        $result = $audit->audit($manifest);

        foreach ($result as $category => $rows) {
            $ids = array_column($rows, 'id');
            $this->line(sprintf(
                '%s count=%d ids=%s',
                $category,
                count($rows),
                $ids === [] ? '-' : implode(',', $ids),
            ));
        }

        if ($manifest !== []) {
            $changes = $audit->backfill($manifest, (bool) $this->option('apply'));

            foreach ($changes as $change) {
                $this->line(sprintf(
                    'payment=%d mode=%s sources=%s',
                    $change['id'],
                    $change['applied'] ? 'applied' : 'dry-run',
                    json_encode($change['sources'], JSON_THROW_ON_ERROR),
                ));
            }
        }

        return $result[PaymentMigrationAuditService::INVALID] === []
            ? self::SUCCESS
            : self::FAILURE;
    }

    /** @return array<int|string, array<string, mixed>>|null */
    private function manifest(): ?array
    {
        $path = $this->option('manifest');

        if ($path === null) {
            if ($this->option('apply')) {
                $this->error('--apply requires --manifest.');

                return null;
            }

            return [];
        }

        try {
            $contents = file_get_contents($path);
            $decoded = is_string($contents)
                ? json_decode($contents, true, 512, JSON_THROW_ON_ERROR)
                : null;
        } catch (JsonException) {
            $decoded = null;
        }

        if (! is_array($decoded)) {
            $this->error('Manifest is unavailable or invalid JSON.');

            return null;
        }

        return $decoded;
    }
}
