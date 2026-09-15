<?php

namespace App\Console\Commands;

use App\Services\Leave\Legacy\LegacyLeaveMigrationService;
use Illuminate\Console\Command;
use Throwable;

class LeaveMigrateLegacyCommand extends Command
{
    protected $signature = 'leave:migrate-legacy
                            {--dry-run : Count mapped rows without writing leaveforms_db}
                            {--leaves-only : Import leave_info only (skip balances)}
                            {--balances-only : Import leave_confirmation balances only}
                            {--attachments-only : Copy V1 Uploads onto already-imported leaves}
                            {--skip-pending : Do not import open leaves as pending_approval}
                            {--fresh : Remove previously migrated legacy leaves/balances first}
                            {--force : Allow running when APP_ENV is not local}';

    protected $description = 'Import legacy formsdb leaves and balances into leaveforms_db';

    public function __construct(
        private readonly LegacyLeaveMigrationService $migrationService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! app()->environment('local') && ! $this->option('force')) {
            $this->components->error('This command only runs when APP_ENV=local. Pass --force to override.');

            return self::FAILURE;
        }

        $leavesOnly = (bool) $this->option('leaves-only');
        $balancesOnly = (bool) $this->option('balances-only');
        $attachmentsOnly = (bool) $this->option('attachments-only');

        $exclusive = array_filter([$leavesOnly, $balancesOnly, $attachmentsOnly]);
        if (count($exclusive) > 1) {
            $this->components->error('Use only one of --leaves-only, --balances-only, or --attachments-only.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $fresh = (bool) $this->option('fresh');
        $includePending = ! (bool) $this->option('skip-pending');
        $doLeaves = ! $balancesOnly && ! $attachmentsOnly;
        $doBalances = ! $leavesOnly && ! $attachmentsOnly;

        $this->info($dryRun ? 'Dry run — no writes to leaveforms_db.' : 'Migrating into leaveforms_db…');
        $this->line('Source: '.config('database.connections.formsdb.database').' (formsdb connection)');
        $this->line('Target: '.config('database.connections.'.config('database.default').'.database'));
        $this->line('Uploads: '.config('leave.legacy.uploads_path'));
        if ($attachmentsOnly) {
            $this->line('Mode: attachments-only (backfill onto imported leaves)');
        } else {
            $this->line('Open pendings: '.($includePending ? 'import as pending_approval (approve in revamp)' : 'skipped'));
        }
        $this->newLine();

        try {
            $result = $this->migrationService->migrate(
                dryRun: $dryRun,
                leaves: $doLeaves,
                balances: $doBalances,
                fresh: $fresh,
                includePending: $includePending,
                attachmentsOnly: $attachmentsOnly,
            );
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($attachmentsOnly) {
            $this->printAttachmentStats($result['attachments'], $dryRun);

            if ($dryRun) {
                $this->components->warn('Dry run complete. Re-run without --dry-run to write.');
            } else {
                $this->components->info('Attachment backfill complete.');
            }

            return self::SUCCESS;
        }

        if ($doLeaves) {
            $leaves = $result['leaves'];
            $this->components->info('Leaves');
            $this->table(
                ['Metric', 'Count'],
                [
                    ['read from formsdb', $leaves['read']],
                    [$dryRun ? 'would insert' : 'inserted', $leaves['inserted']],
                    [$dryRun ? 'would insert pending' : 'pending_approval inserted', $leaves['pending_inserted'] ?? 0],
                    ['skipped (already imported)', $leaves['skipped_existing']],
                    ['skipped (unmapped / invalid)', $leaves['skipped']],
                ],
            );

            if ($leaves['skipped_reasons'] !== []) {
                $this->line('Skip reasons:');
                foreach ($leaves['skipped_reasons'] as $reason => $count) {
                    $this->line("  - {$reason}: {$count}");
                }
            }

            $att = $leaves['attachments'] ?? $result['attachments'];
            $this->newLine();
            $this->printAttachmentStats($att, $dryRun, inlineWithLeaves: true);
            $this->newLine();
        }

        if ($doBalances) {
            $balances = $result['balances'];
            $this->components->info('Balances');
            $this->table(
                ['Metric', 'Count'],
                [
                    ['credit cells read', $balances['read']],
                    [$dryRun ? 'would insert' : 'inserted', $balances['inserted']],
                    ['skipped (already has credits)', $balances['skipped_existing']],
                    ['skipped (zero / invalid)', $balances['skipped']],
                ],
            );

            if ($balances['skipped_reasons'] !== []) {
                $this->line('Skip reasons:');
                foreach ($balances['skipped_reasons'] as $reason => $count) {
                    $this->line("  - {$reason}: {$count}");
                }
            }
            $this->newLine();
        }

        if ($dryRun) {
            $this->components->warn('Dry run complete. Re-run without --dry-run to write.');
        } else {
            $this->components->info('Migration complete.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{read?: int, copied: int, missing: int, skipped_existing: int, skipped_no_name: int, skipped_reasons?: array<string, int>}  $att
     */
    private function printAttachmentStats(array $att, bool $dryRun, bool $inlineWithLeaves = false): void
    {
        $this->components->info($inlineWithLeaves ? 'Attachments (with leave import)' : 'Attachments');
        $rows = [];
        if (isset($att['read'])) {
            $rows[] = ['imported leaves scanned', $att['read']];
        }
        $rows[] = [$dryRun ? 'would copy from Uploads' : 'copied from Uploads', $att['copied']];
        $rows[] = ['missing on disk', $att['missing']];
        $rows[] = ['skipped (already has attachment)', $att['skipped_existing']];
        $rows[] = ['no legacy filename', $att['skipped_no_name']];

        $this->table(['Metric', 'Count'], $rows);

        if (($att['skipped_reasons'] ?? []) !== []) {
            $this->line('Attachment notes:');
            foreach ($att['skipped_reasons'] as $reason => $count) {
                $this->line("  - {$reason}: {$count}");
            }
        }
    }
}
