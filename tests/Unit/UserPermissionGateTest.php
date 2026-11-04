<?php

namespace Tests\Unit;

use App\Services\Kdtph\UserPermissionGate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserPermissionGateTest extends TestCase
{
    private UserPermissionGate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.kdtphdb' => config('database.connections.sqlite')]);
        DB::purge('kdtphdb');
        DB::connection('kdtphdb')->setPdo(DB::connection()->getPdo());

        Schema::connection('kdtphdb')->dropIfExists('user_permissions');
        Schema::connection('kdtphdb')->create('user_permissions', function (Blueprint $table): void {
            $table->increments('userp_id');
            $table->unsignedInteger('permission_id');
            $table->unsignedInteger('fldEmployeeNum');
        });

        DB::connection('kdtphdb')->table('user_permissions')->insert([
            'permission_id' => 59,
            'fldEmployeeNum' => 10,
        ]);

        config(['leave.all_employee_monthly_report_permission_id' => 59]);

        $this->gate = new UserPermissionGate;
    }

    public function test_employee_with_the_configured_grant_can_view_all_employees(): void
    {
        $this->assertTrue($this->gate->canViewAllEmployeeMonthlyReport(10));
    }

    public function test_employee_without_the_grant_cannot_view_all_employees(): void
    {
        $this->assertFalse($this->gate->canViewAllEmployeeMonthlyReport(11));
    }

    public function test_a_different_permission_id_does_not_grant_access(): void
    {
        config(['leave.all_employee_monthly_report_permission_id' => 13]);

        $this->assertFalse($this->gate->canViewAllEmployeeMonthlyReport(10));
    }

    public function test_blank_permission_id_disables_the_grant(): void
    {
        config(['leave.all_employee_monthly_report_permission_id' => null]);

        $this->assertFalse($this->gate->canViewAllEmployeeMonthlyReport(10));
        $this->assertFalse($this->gate->has(10, ''));
        $this->assertFalse($this->gate->has(10, 0));
    }
}
