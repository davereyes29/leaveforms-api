# formsdb — Schema (Revamp)

Database schema for the Leave Form Revamp. Employee data is read from **kdtphdb** (separate connection).

**Related docs:** `BALANCE_FLOW.md` · `APPROVAL_RULES.md` · `LEGACY_NOTES.md`

---

## Tables (10)

| Table | Purpose |
|-------|---------|
| `leave_type` | Leave type lookup (VL, SL, …) |
| `leave_info` | Leave requests |
| `leave_balance` | Rolling paid credits (`available`, `reserved`) |
| `leave_balance_transaction` | Ledger of balance changes |
| `leave_approval_requirement` | Required approvals snapshotted at submit |
| `leave_approval_history` | One immutable decision per requirement |
| `leave_activity_history` | User/system activity audit (leaves + admin credit grants) |
| `leave_activity_recipient` | Recipients of a summarized credit-distribution activity |
| `leave_conversion` | VL → SL conversion (med cert) |
| `leave_admin_reminder` | Admin approval-reminder sends (bulk and per-leave singles) |

---

## `leave_type`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `code` | varchar | e.g. `VL`, `SL` — **UNIQUE** |
| `type` | varchar | Display name |

---

## `leave_info`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `emp_id` | bigint | Employee on leave |
| `filed_by_emp_id` | bigint | Who submitted (employee or Group Leader) |
| `leave_type_id` | bigint | FK → `leave_type.id` |
| `duration` | decimal | Total days |
| `paid_days` | decimal | Days covered by paid credits |
| `lwop_days` | decimal | Leave without pay (not in balance buckets) |
| `day_type` | enum | See below |
| `start_date` | date | |
| `end_date` | date | |
| `leave_reason` | text | |
| `leave_attachment` | varchar | Nullable |
| `status` | enum | See below |
| `cancellation_reason` | text | Nullable; **required** when `status = cancelled` |
| `confirmed_at` | datetime | Nullable; set when `status = confirmed` |
| `created_at` | datetime | |
| `updated_at` | datetime | |

**Rules**

- `paid_days + lwop_days = duration`
- `duration > 0`
- LWOP confirm: **no** `leave_balance_transaction` row

### Enums — `leave_info.status`

```
pending_approval
confirmed
pending_conversion
rejected
cancelled
converted_to_sl
```

### Enums — `leave_info.day_type`

```
full
half_am
half_pm
```

---

## `leave_balance`

One **rolling** balance per employee and leave type. No `balance_year`. No `used` column.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `emp_id` | bigint | |
| `leave_type_id` | bigint | FK → `leave_type.id` |
| `available` | decimal | Unused paid credits (stackable via accrual) |
| `reserved` | decimal | Paid credits held for pending requests |
| `created_at` | datetime | |
| `updated_at` | datetime | |

**Unique:** `(emp_id, leave_type_id)`

Consumed leave is **not** stored on this table. Use confirmed `leave_info` rows for reporting.

---

## `leave_balance_transaction`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `emp_id` | bigint | |
| `leave_id` | bigint | Nullable FK → `leave_info.id` |
| `leave_type_id` | bigint | FK → `leave_type.id` |
| `available_delta` | decimal | Change to `available` (+/−) |
| `reserved_delta` | decimal | Change to `reserved` (+/−) |
| `available_after` | decimal | Snapshot |
| `reserved_after` | decimal | Snapshot |
| `reason` | enum | See below |
| `remarks` | text | Nullable |
| `created_at` | datetime | |

### Enums — `leave_balance_transaction.reason`

```
leave_reserved
leave_confirmed
leave_reservation_released
vl_to_sl_restore
vl_to_sl_deduct
monthly_accrual
manual_adjustment
leave_reversed
initial_balance
annual_entitlement
regularization_entitlement
contractual_entitlement
```

| Reason | Typical deltas | When |
|--------|----------------|------|
| `initial_balance` | `available` +N | Opening balance (migration or first setup) |
| `annual_entitlement` | `available` +N | Yearly VL/SL grant (15/15 or 15/18 by tenure) |
| `regularization_entitlement` | `available` +N | Mid-year newly regularized grant (VL/SL prorated; PTB/EL flat) |
| `contractual_entitlement` | `available` +N | Admin-typed VL/SL for contractuals (max 15 each) |
| `monthly_accrual` | `available` +1.25 | Reserved for a future monthly job (not used by admin UI) |
| `leave_reserved` | available −N, reserved +N | Paid leave filed |
| `leave_confirmed` | reserved −N | All requirements approved |
| `leave_reservation_released` | reserved −N, available +N | Reject or employee cancel |
| `vl_to_sl_restore` | VL available +N | Conversion approved |
| `vl_to_sl_deduct` | SL available −N (or reserved/used path per policy) | Conversion approved |
| `leave_reversed` | Reverse prior confirm | Admin undo |
| `manual_adjustment` | Per HR edit | Correction |

Bucket semantics: see `BALANCE_FLOW.md`.

---

## `leave_approval_requirement`

Snapshotted when the request is **submitted**. Does not change if `formspic` or employee group changes later.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `leave_id` | bigint | FK → `leave_info.id` |
| `display_order` | tinyint | 1, 2, … |
| `allowed_levels` | json | Nullable; P1–P4 level sets |
| `required_approver_type` | varchar | Nullable; `president` or `admin` |
| `is_satisfied` | boolean | Default `false` |
| `satisfied_at` | datetime | Nullable |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

**Unique:** `(leave_id, display_order)`

**Row rule (exactly one):**

- `allowed_levels` IS NOT NULL and `required_approver_type` IS NULL — manager-level rules  
- `allowed_levels` IS NULL and `required_approver_type` IS NOT NULL — special approvers  

### `allowed_levels` examples

| Value | Meaning |
|-------|---------|
| `[1, 2, 3, 4]` | One from P1, P2, P3, or P4 |
| `[2, 3, 4]` | One from P2, P3, or P4 |
| `[3, 4]` | One from P3 or P4 |
| `[4]` | P4 specifically |

### `required_approver_type` values

```
president
admin
```

(`admin` for leave types that need admin approval on the original request — PTB, maternity, emergency, etc.)

### Example snapshot — regular employee (VL)

| display_order | allowed_levels | required_approver_type |
|---------------|----------------|------------------------|
| 1 | `[1, 2, 3, 4]` | NULL |

### Example snapshot — P4 employee

| display_order | allowed_levels | required_approver_type |
|---------------|----------------|------------------------|
| 1 | NULL | `president` |

---

## `leave_approval_history`

**One immutable row per requirement.** First approver who acts on that requirement inserts the only row.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `leave_id` | bigint | FK → `leave_info.id` |
| `requirement_id` | bigint | FK → `leave_approval_requirement.id` — **UNIQUE** |
| `approver_emp_id` | bigint | |
| `approval_level` | tinyint | Nullable; 1–4 when manager-level |
| `approver_type` | enum | See below |
| `is_approved` | boolean | `true` = approved, `false` = rejected |
| `remarks` | text | Required when `is_approved = false` |
| `created_at` | datetime | |

**Unique:** `(requirement_id)`

**Not used for:** employee cancellation (see `leave_activity_history`).

### Enums — `leave_approval_history.approver_type`

```
leader
am
dm
sm
admin
president
```

| `approval_level` | `approver_type` |
|------------------|-----------------|
| 1 | `leader` |
| 2 | `am` |
| 3 | `dm` |
| 4 | `sm` |
| NULL | `admin` or `president` |

---

## `leave_activity_history`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `leave_id` | bigint | Nullable FK → `leave_info.id`. Null on credit-grant rows |
| `subject_emp_id` | bigint | Nullable. Set on solo credit adjustments; null = **"Multiple Employees"** batch |
| `leave_type_id` | bigint | Nullable FK → `leave_type.id`. Set on credit-grant rows (leave rows use `leave_info.leave_type_id`) |
| `performed_by_emp_id` | bigint | |
| `activity_type` | varchar(50) | See below |
| `remarks` | text | Nullable |
| `created_at` | datetime | |

### Enums — `leave_activity_history.activity_type`

```
submitted
modified
cancelled
rejected
attachment_added
leave_confirmed
conversion_requested
conversion_approved
conversion_rejected
annual_credits_distributed
regularization_credits_distributed
contractual_credits_distributed
credits_adjusted
```

Credit-grant types have `leave_id` null. Batch distributions also write `leave_activity_recipient` rows (one per employee who received that leave type in the Confirm). Manual adjust uses `subject_emp_id` and no recipient rows.

---

## `leave_activity_recipient`

Employees covered by a summarized credit-distribution activity. Used so admin log filters (`q`, `group_id`) can still match a batch row.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `activity_id` | bigint | FK → `leave_activity_history.id` (cascade delete) |
| `emp_id` | bigint | Recipient |

**Unique:** `(activity_id, emp_id)`

---

## `leave_conversion`

VL → SL after return (unexpected sickness path). Admin reviews med cert here.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `leave_id` | bigint | FK → `leave_info.id` |
| `from_leave_type_id` | bigint | FK → `leave_type.id` |
| `to_leave_type_id` | bigint | FK → `leave_type.id` |
| `med_cert_attachment` | varchar | Nullable |
| `reviewed_by_emp_id` | bigint | Nullable; admin |
| `status` | enum | See below |
| `remarks` | text | Nullable |
| `reviewed_at` | datetime | Nullable |
| `created_at` | datetime | |
| `updated_at` | datetime | |

### Enums — `leave_conversion.status`

```
pending_review
approved
rejected
```

**Approved:** restore VL + deduct SL (transactions), `leave_info.status` → `converted_to_sl`, update `leave_type_id` to SL.

**Rejected:** no balance changes; leave stays VL; `leave_info.status` → `confirmed`.

**Requested:** `leave_info.status` → `pending_conversion` while `leave_conversion.status` is `pending_review`.

---

## `leave_admin_reminder`

Admin approval reminders. **One row per successful send**. Locks are independent and use the Asia/Manila calendar day:

- **Bulk** — at most one `kind = bulk` row per Manila day. Does not lock singles. Generic email with that approver’s pending count (no leave list).
- **Single** — at most one `kind = single` row per `(leave_id, target_emp_id)` per Manila day. Does not lock bulk.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `kind` | varchar | `bulk` or `single` |
| `sent_by_emp_id` | bigint | Admin who clicked send |
| `leave_id` | bigint | Nullable; set for `single` |
| `target_emp_id` | bigint | Nullable; set for `single` |
| `approver_count` | unsigned int | Unique waiting approvers mailed (or intended) |
| `request_count` | unsigned int | Unique pending leaves in that send |
| `email_count` | unsigned int | Messages that actually went out |
| `sent_at` | datetime | Stored in app timezone (UTC); compared in Asia/Manila |
| `created_at` | datetime | |
| `updated_at` | datetime | |

Not an activity-log event. Recipients exclude `LEAVE_ADMIN_EMP_ID`.

---

## Indexes

### `leave_info`

```
INDEX (emp_id, status, start_date)
INDEX (emp_id, start_date)
INDEX (status, start_date)
INDEX (start_date, end_date)
INDEX (filed_by_emp_id, created_at)
INDEX (leave_type_id, status, start_date)
```

### `leave_approval_requirement`

```
UNIQUE (leave_id, display_order)
INDEX (leave_id, is_satisfied)
```

### `leave_approval_history`

```
UNIQUE (requirement_id)
INDEX (leave_id, created_at)
INDEX (approver_emp_id, created_at)
```

### `leave_balance`

```
UNIQUE (emp_id, leave_type_id)
```

### `leave_balance_transaction`

```
INDEX (emp_id, created_at)
INDEX (leave_id, reason)
INDEX (leave_type_id, created_at)
```

### `leave_conversion`

```
INDEX (leave_id)
INDEX (status, created_at)
```

### `leave_admin_reminder`

```
INDEX (sent_at)
```

### `leave_activity_history`

```
INDEX (leave_id, created_at)
INDEX (created_at)
INDEX (subject_emp_id)
```

### `leave_activity_recipient`

```
UNIQUE (activity_id, emp_id)
INDEX (emp_id)
```

### `leave_type`

```
UNIQUE (code)
```

---

## External references (kdtphdb)

| Field | Source |
|-------|--------|
| `emp_id`, `filed_by_emp_id`, `approver_emp_id`, … | `employee_list` |
| Approver config at submit | `formspic` → snapshot into `leave_approval_requirement` |
| Workdays / holidays | `kdtholiday` |

---

## Laravel notes

- `decimal(8, 2)` for day counts.
- Backed PHP enums matching columns above.
- Balance changes only through services + `DB::transaction()`.
- On submit: insert `leave_approval_requirement` rows, then notify approvers.
