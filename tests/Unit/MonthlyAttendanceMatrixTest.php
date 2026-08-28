<?php

namespace Tests\Unit;

use App\Enums\Leave\DayType;
use App\Enums\Leave\MonthlyAttendanceCutOff;
use App\Services\Leave\LeaveDurationService;
use App\Services\Leave\MonthlyAttendanceMatrix;
use PHPUnit\Framework\TestCase;

class MonthlyAttendanceMatrixTest extends TestCase
{
    private MonthlyAttendanceMatrix $matrix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->matrix = new MonthlyAttendanceMatrix(new LeaveDurationService);
    }

    public function test_monthly_cut_off_is_the_full_calendar_month(): void
    {
        [$start, $end] = $this->matrix->period('2026-07', MonthlyAttendanceCutOff::Monthly);

        $this->assertSame('2026-07-01', $start);
        $this->assertSame('2026-07-31', $end);
    }

    public function test_first_half_is_the_1st_through_the_15th(): void
    {
        [$start, $end] = $this->matrix->period('2026-07', MonthlyAttendanceCutOff::FirstHalf);

        $this->assertSame('2026-07-01', $start);
        $this->assertSame('2026-07-15', $end);
    }

    public function test_second_half_is_the_16th_through_month_end(): void
    {
        [$start, $end] = $this->matrix->period('2026-02', MonthlyAttendanceCutOff::SecondHalf);

        $this->assertSame('2026-02-16', $start);
        $this->assertSame('2026-02-28', $end);
    }

    public function test_date_columns_include_weekends_and_holidays_as_non_working(): void
    {
        $columns = $this->matrix->dateColumns('2026-07-03', '2026-07-06', ['2026-07-03']);

        $this->assertSame(
            [
                [
                    'date' => '2026-07-03',
                    'day_of_month' => 3,
                    'weekday_short' => 'Fri',
                    'is_non_working' => true,
                ],
                [
                    'date' => '2026-07-04',
                    'day_of_month' => 4,
                    'weekday_short' => 'Sat',
                    'is_non_working' => true,
                ],
                [
                    'date' => '2026-07-05',
                    'day_of_month' => 5,
                    'weekday_short' => 'Sun',
                    'is_non_working' => true,
                ],
                [
                    'date' => '2026-07-06',
                    'day_of_month' => 6,
                    'weekday_short' => 'Mon',
                    'is_non_working' => false,
                ],
            ],
            $columns,
        );
    }

    public function test_paid_vl_maps_to_vl_and_skips_weekends(): void
    {
        $cells = $this->matrix->cellsForLeave(
            'VL',
            DayType::Full,
            '2026-07-17',
            '2026-07-20',
            '2.00',
            '2026-07-01',
            '2026-07-31',
            [],
        );

        $this->assertSame(
            [
                $this->cell('2026-07-17', 'vl', '1.00'),
                $this->cell('2026-07-20', 'vl', '1.00'),
            ],
            $cells,
        );
    }

    public function test_converted_sl_code_maps_to_sl_not_vl(): void
    {
        $cells = $this->matrix->cellsForLeave(
            'SL',
            DayType::Full,
            '2026-07-13',
            '2026-07-13',
            '1.00',
            '2026-07-01',
            '2026-07-31',
            [],
        );

        $this->assertSame([$this->cell('2026-07-13', 'sl', '1.00')], $cells);
    }

    public function test_paid_el_maps_to_others(): void
    {
        $cells = $this->matrix->cellsForLeave(
            'EL',
            DayType::Full,
            '2026-07-13',
            '2026-07-13',
            '1.00',
            '2026-07-01',
            '2026-07-31',
            [],
        );

        $this->assertSame([$this->cell('2026-07-13', 'others', '1.00')], $cells);
    }

    public function test_lwop_is_assigned_to_the_last_workdays(): void
    {
        $cells = $this->matrix->cellsForLeave(
            'VL',
            DayType::Full,
            '2026-07-13',
            '2026-07-16',
            '2.00',
            '2026-07-01',
            '2026-07-31',
            [],
        );

        $this->assertSame(
            [
                $this->cell('2026-07-13', 'vl', '1.00'),
                $this->cell('2026-07-14', 'vl', '1.00'),
                $this->cell('2026-07-15', 'lwop', '1.00'),
                $this->cell('2026-07-16', 'lwop', '1.00'),
            ],
            $cells,
        );
    }

    public function test_lwop_tail_is_computed_on_the_full_range_then_clipped(): void
    {
        $cells = $this->matrix->cellsForLeave(
            'VL',
            DayType::Full,
            '2026-07-13',
            '2026-07-16',
            '2.00',
            '2026-07-15',
            '2026-07-31',
            [],
        );

        $this->assertSame(
            [
                $this->cell('2026-07-15', 'lwop', '1.00'),
                $this->cell('2026-07-16', 'lwop', '1.00'),
            ],
            $cells,
        );
    }

    public function test_half_am_sick_leave_emits_half_and_amount(): void
    {
        $cells = $this->matrix->cellsForLeave(
            'SL',
            DayType::HalfAm,
            '2026-07-13',
            '2026-07-13',
            '0.50',
            '2026-07-01',
            '2026-07-31',
            [],
        );

        $this->assertSame(
            [$this->cell('2026-07-13', 'sl', '0.50', 'am')],
            $cells,
        );
    }

    public function test_long_leave_is_lwop_on_calendar_days_including_weekend(): void
    {
        $cells = $this->matrix->cellsForLeave(
            'LL',
            DayType::Full,
            '2026-09-04',
            '2026-09-06',
            '0.00',
            '2026-09-01',
            '2026-09-30',
            [],
        );

        $this->assertSame(
            [
                $this->cell('2026-09-04', 'lwop', '1.00'),
                $this->cell('2026-09-05', 'lwop', '1.00', null, true),
                $this->cell('2026-09-06', 'lwop', '1.00', null, true),
            ],
            $cells,
        );
    }

    public function test_ptb_paints_weekend_columns_as_others(): void
    {
        $cells = $this->matrix->cellsForLeave(
            'PTB',
            DayType::Full,
            '2026-09-04',
            '2026-09-06',
            '3.00',
            '2026-09-01',
            '2026-09-30',
            [],
        );

        $this->assertSame(
            [
                $this->cell('2026-09-04', 'others', '1.00'),
                $this->cell('2026-09-05', 'others', '1.00', null, true),
                $this->cell('2026-09-06', 'others', '1.00', null, true),
            ],
            $cells,
        );
    }

    public function test_mixed_paid_and_lwop_on_the_last_full_day_collapses_to_lwop(): void
    {
        $cells = $this->matrix->cellsForLeave(
            'VL',
            DayType::Full,
            '2026-07-13',
            '2026-07-15',
            '2.50',
            '2026-07-01',
            '2026-07-31',
            [],
        );

        $this->assertSame(
            [
                $this->cell('2026-07-13', 'vl', '1.00'),
                $this->cell('2026-07-14', 'vl', '1.00'),
                $this->cell('2026-07-15', 'lwop', '1.00'),
            ],
            $cells,
        );
    }

    public function test_same_category_am_and_pm_collapse_to_a_full_day(): void
    {
        $merged = $this->matrix->mergeCells([
            $this->cell('2026-07-13', 'vl', '0.50', 'am'),
            $this->cell('2026-07-13', 'vl', '0.50', 'pm'),
        ]);

        $this->assertSame([$this->cell('2026-07-13', 'vl', '1.00')], $merged);
    }

    public function test_lwop_wins_when_am_and_pm_categories_differ(): void
    {
        $merged = $this->matrix->mergeCells([
            $this->cell('2026-07-13', 'vl', '0.50', 'am'),
            $this->cell('2026-07-13', 'lwop', '0.50', 'pm'),
        ]);

        $this->assertSame([$this->cell('2026-07-13', 'lwop', '1.00')], $merged);
    }

    public function test_totals_sum_cell_amounts_and_grand_totals_sum_rows(): void
    {
        $rowA = $this->matrix->totalsForCells([
            $this->cell('2026-07-13', 'vl', '1.00'),
            $this->cell('2026-07-14', 'sl', '0.50', 'am'),
        ]);
        $rowB = $this->matrix->totalsForCells([
            $this->cell('2026-07-13', 'others', '1.00'),
            $this->cell('2026-07-15', 'lwop', '1.00'),
        ]);

        $this->assertSame(
            ['vl' => '1.00', 'sl' => '0.50', 'others' => '0.00', 'lwop' => '0.00'],
            $rowA,
        );
        $this->assertSame(
            ['vl' => '1.00', 'sl' => '0.50', 'others' => '1.00', 'lwop' => '1.00'],
            $this->matrix->grandTotals([$rowA, $rowB]),
        );
    }

    /**
     * @return array{date: string, category: string, amount: string, half: string|null, is_non_working: bool}
     */
    private function cell(
        string $date,
        string $category,
        string $amount,
        ?string $half = null,
        bool $isNonWorking = false,
    ): array {
        return [
            'date' => $date,
            'category' => $category,
            'amount' => $amount,
            'half' => $half,
            'is_non_working' => $isNonWorking,
        ];
    }
}
