<?php

namespace App\Console\Commands;

use App\Services\Leave\CreditSheet\LeaveCreditSheetImporter;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class LeaveImportCreditSheetCommand extends Command
{
    protected $signature = 'leave:import-credit-sheet
                            {path : Path to the admin VL/SL credit workbook (.xlsx)}
                            {--dry-run : Show VL/SL replacements without writing}
                            {--force : Allow running when APP_ENV is not local}';

    protected $description = 'Replace VL and SL available credits with the August 30 admin credit sheet';

    public function __construct(
        private readonly LeaveCreditSheetImporter $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! app()->environment('local') && ! $this->option('force')) {
            $this->components->error('This command only runs when APP_ENV=local. Pass --force to override.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun
            ? 'Dry run — VL and SL will not be changed.'
            : 'Replacing VL and SL with the admin credit sheet…');
        $this->line('Sheet: '.$path);
        $this->line('EL and PTB are left unchanged.');
        $this->newLine();

        try {
            $result = $this->importer->import($path, $dryRun);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['employees on sheet', $result['employees']],
                ['VL to replace', $result['vl_updated']],
                ['SL to replace', $result['sl_updated']],
                ['already matching', $result['unchanged']],
                ['missing from employee_list', count($result['missing'])],
            ],
        );

        if ($result['changes'] !== []) {
            $this->newLine();
            $this->line($result['wrote'] ? 'Replaced:' : 'Would replace:');
            $this->table(
                ['Emp', 'Name', 'VL now', 'VL sheet', 'SL now', 'SL sheet'],
                array_map(static fn (array $change): array => [
                    $change['emp_id'],
                    $change['name'],
                    $change['vl_from'],
                    $change['vl_to'],
                    $change['sl_from'],
                    $change['sl_to'],
                ], $result['changes']),
            );
        }

        if ($result['missing'] !== []) {
            $this->newLine();
            $this->components->error('These employee numbers are not in employee_list. No balances were changed.');
            $this->table(
                ['Row', 'Emp', 'Name'],
                array_map(static fn (array $missing): array => [
                    $missing['row'],
                    $missing['emp_id'],
                    $missing['name'],
                ], $result['missing']),
            );

            return self::FAILURE;
        }

        $this->newLine();

        if ($dryRun) {
            $this->components->warn('Dry run complete. Re-run without --dry-run to write.');
        } elseif ($result['wrote']) {
            $this->components->info('VL and SL now match the admin credit sheet.');
        } else {
            $this->components->info('VL and SL already match the admin credit sheet.');
        }

        return self::SUCCESS;
    }
}
