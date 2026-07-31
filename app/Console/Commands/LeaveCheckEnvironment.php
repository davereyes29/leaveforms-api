<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class LeaveCheckEnvironment extends Command
{
    protected $signature = 'leave:check-env';

    protected $description = 'Verify leaveforms_db and kdtphdb connections and key config';

    public function handle(): int
    {
        $this->info('Leave Forms — environment check');
        $this->newLine();

        $ok = true;

        $ok = $this->checkDatabase('leaveforms_db', config('database.default')) && $ok;
        $ok = $this->checkDatabase('kdtphdb', 'kdtphdb') && $ok;
        $ok = $this->checkDatabase('kdtphdb_new', 'kdtphdb_new') && $ok;

        $this->newLine();
        $this->line('Config:');
        $this->table(
            ['Key', 'Value'],
            [
                ['APP_URL', config('app.url')],
                ['DB (default)', config('database.connections.'.config('database.default').'.database')],
                ['kdtphdb', config('database.connections.kdtphdb.database')],
                ['kdtphdb_new', config('database.connections.kdtphdb_new.database')],
                ['PORTAL_COOKIE_NAME', config('leave.portal_cookie_name')],
                ['FRONTEND_URL', config('leave.frontend_url')],
                ['LEAVE_PRESIDENT_EMP_ID', config('leave.president_emp_id') ?: '(not set yet)'],
                ['LEAVE_ADMIN_EMP_ID', config('leave.admin_emp_id') ?: '(not set yet)'],
            ]
        );

        if (! config('leave.president_emp_id')) {
            $this->warn('LEAVE_PRESIDENT_EMP_ID is not set — required before approval routing works.');
        }

        if (! config('leave.admin_emp_id')) {
            $this->warn('LEAVE_ADMIN_EMP_ID is not set — required before VL → SL conversion review works.');
        }

        $this->newLine();

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function checkDatabase(string $label, string $connection): bool
    {
        $database = config("database.connections.{$connection}.database");

        try {
            DB::connection($connection)->getPdo();
            DB::connection($connection)->select('SELECT 1 AS ok');

            $this->components->info("{$label} ({$database}) — connected");

            return true;
        } catch (Throwable $e) {
            $this->components->error("{$label} ({$database}) — failed");
            $this->line('  '.$e->getMessage());

            return false;
        }
    }
}
