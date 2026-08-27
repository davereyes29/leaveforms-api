<?php

namespace App\Models\Leave;

use App\Enums\Leave\ActivityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveActivityHistory extends Model
{
    public $timestamps = false;

    const CREATED_AT = 'created_at';

    const UPDATED_AT = null;

    protected $table = 'leave_activity_history';

    protected $fillable = [
        'leave_id',
        'subject_emp_id',
        'leave_type_id',
        'performed_by_emp_id',
        'activity_type',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'leave_id' => 'integer',
            'subject_emp_id' => 'integer',
            'leave_type_id' => 'integer',
            'performed_by_emp_id' => 'integer',
            'activity_type' => ActivityType::class,
            'created_at' => 'datetime',
        ];
    }

    public function leaveInfo(): BelongsTo
    {
        return $this->belongsTo(LeaveInfo::class, 'leave_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(LeaveActivityRecipient::class, 'activity_id');
    }

    /**
     * One summarized admin-log row per leave type granted in a Confirm.
     *
     * @param  list<int>  $recipientEmpIds
     */
    public static function recordBatchCreditDistribution(
        int $performedByEmpId,
        ActivityType $activityType,
        int $leaveTypeId,
        array $recipientEmpIds,
        ?string $remarks = null,
    ): ?self {
        $recipientEmpIds = array_values(array_unique(array_map('intval', $recipientEmpIds)));

        if ($recipientEmpIds === []) {
            return null;
        }

        $activity = self::query()->create([
            'leave_id' => null,
            'subject_emp_id' => null,
            'leave_type_id' => $leaveTypeId,
            'performed_by_emp_id' => $performedByEmpId,
            'activity_type' => $activityType,
            'remarks' => $remarks,
        ]);

        LeaveActivityRecipient::query()->insert(
            array_map(fn (int $empId): array => [
                'activity_id' => $activity->id,
                'emp_id' => $empId,
            ], $recipientEmpIds),
        );

        return $activity;
    }

    /**
     * One solo admin-log row for a manual available-credit adjustment.
     */
    public static function recordSoloCreditAdjustment(
        int $performedByEmpId,
        int $subjectEmpId,
        int $leaveTypeId,
        ?string $remarks = null,
    ): self {
        return self::query()->create([
            'leave_id' => null,
            'subject_emp_id' => $subjectEmpId,
            'leave_type_id' => $leaveTypeId,
            'performed_by_emp_id' => $performedByEmpId,
            'activity_type' => ActivityType::CreditsAdjusted,
            'remarks' => $remarks,
        ]);
    }

    public function isBatchCredit(): bool
    {
        return $this->leave_id === null && $this->subject_emp_id === null;
    }
}
