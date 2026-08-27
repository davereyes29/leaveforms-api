# Balance Flow (Revamp)

How paid leave credits move between **`available`** and **`reserved`**, and when ledger rows are written.

**Schema reference:** `SCHEMA.md`  
**Approval side effects:** `APPROVAL_RULES.md`

---

## Two buckets

| Bucket | Meaning |
|--------|---------|
| **available** | Paid credits the employee can still use. Grows via accrual. |
| **reserved** | Paid credits held for `pending_approval` requests. |

There is **no `used` column** and **no balance_year**. One rolling row per `(emp_id, leave_type_id)`.

**LWOP** is stored on `leave_info.lwop_days` only. It does **not** change either bucket.

**Reporting “how much leave was taken”** — sum confirmed `leave_info` rows (`duration`, `paid_days`, `lwop_days`), not `leave_balance`.

---

## Core rules

1. Every bucket change → insert `leave_balance_transaction` with `available_delta`, `reserved_delta`, and `*_after` snapshots.
2. Run all changes inside a **DB transaction**; lock the `leave_balance` row (`lockForUpdate`).
3. On `leave_info`: **`paid_days + lwop_days = duration`** always.
4. **Reserve only `paid_days`**, never `lwop_days`.
5. **LWOP confirm** → **no** `leave_balance_transaction` row.

---

## How credits enter the system

### `initial_balance`

**When:** Revamp go-live, or first time an employee is tracked.

**Effect:** `available` +N (per leave type).

**Migration from legacy:** A one-time script reads legacy balances (e.g. `leave_confirmation.lc_vl`, `lc_sl`) and inserts `leave_balance` + `initial_balance` rows automatically. HR does **not** enter these manually unless correcting an exception.

```
available_delta = +N
reserved_delta  = 0
reason          = initial_balance
leave_id        = NULL
```

---

### `annual_entitlement`

**When:** Admin confirms yearly grant via `POST /api/admin/annual-entitlements` (`emp_ids` that were checked).

**Amount (confirmed):** tenure is complete years from `date_hired` to **1 January** of the grant year (Asia/Manila).

| Tenure | VL | SL |
|--------|----|----|
| Less than 3 years | +15 | +15 |
| 3 years or more | +15 | +18 |

Write **one transaction per leave type** (VL row and SL row). `reserved` unchanged. Also writes **one summarized** `leave_activity_history` row per type granted (`annual_credits_distributed`, recipients in `leave_activity_recipient`). Same employee cannot receive both types twice in the same Manila calendar year (ledger check).

```
available_delta = +15 (or +18 for SL)
reserved_delta  = 0
reason          = annual_entitlement
leave_id        = NULL
```

---

### `monthly_accrual`

Reserved enum value for a possible future scheduled +1.25/month job. **Not** used by the admin newly-regularized screen.

---

### `regularization_entitlement`

**When:** Admin confirms mid-year grant via `POST /api/admin/newly-regularized` (`emp_ids` that were checked).

**Who:** Active, non-contractual employees hired **after** 1 January of the grant year, with tenure **≥ 6 months and &lt; 12 months** as of today (Asia/Manila), who have not received `annual_entitlement` that year.

**Amount:**

| Type | Formula |
|------|---------|
| VL / SL | Inclusive calendar months from (`date_hired` + 6 months) through December × **1.25** |
| PTB | +180 (flat, one-time) |
| EL | +5 (flat, one-time) |

Write **one transaction per leave type**. `reserved` unchanged. Also writes **one summarized** `leave_activity_history` row per type granted (`regularization_credits_distributed`). Same employee cannot receive all four types twice in the same Manila calendar year (ledger check).

```
available_delta = +N
reserved_delta  = 0
reason          = regularization_entitlement
leave_id        = NULL
```

---

### `contractual_entitlement`

**When:** Admin confirms typed VL/SL via `POST /api/admin/contractual-entitlements`.

**Who:** Active contractual employees only.

**Amount:** Admin-typed positive VL and/or SL, each at most **15.00**. May be granted more than once (no yearly lock). Remarks fixed: `"Contractual leave credits"`. Also writes **one summarized** `leave_activity_history` row per type granted (`contractual_credits_distributed`).

```
available_delta = +N
reserved_delta  = 0
reason          = contractual_entitlement
leave_id        = NULL
```

---

### `manual_adjustment`

**When:** HR corrects a balance via `POST /api/admin/balances`.

Available only (`reserved_delta` = 0). `available_delta` is signed (`+3` / `-3`). Always record `remarks`. Also writes a solo `leave_activity_history` row (`credits_adjusted`, `subject_emp_id` set).

---

## Filing leave (paid credits)

### Step 1 — Calculate days

From `start_date`, `end_date`, `day_type`, and leave type:

- **VL / SL / EL:** count workdays (exclude weekends/holidays per `kdtholiday`). VL/SL half-day → `0.5`. EL is full day only.
- **PTB:** inclusive calendar days (weekends and holidays count). Full day only.
- **LL:** inclusive calendar days, full day only, **1–3 calendar months** (end ≥ start + 1 month − 1 day and ≤ start + 3 months − 1 day).

Set `duration`. Split into `paid_days` and `lwop_days` (see LWOP section). **PTB and EL never use LWOP:** if duration > remaining credits of that type, submit is **422**. **LL is always unpaid** (`paid_days = 0`, `lwop_days = duration`); no credits are checked or reserved.

### Step 2 — Reserve (`leave_reserved`)

**When:** Submit with `paid_days > 0` and `status` → `pending_approval`. Uses the leave type being filed (VL credits for VL, SL for SL, PTB for PTB, EL for EL). **LL never reserves** (`paid_days` is always 0).

**Effect:**

```
available_delta = −paid_days
reserved_delta  = +paid_days
reason          = leave_reserved
```

**Example:** `available = 15`, file 2 paid days → `available = 13`, `reserved = 2`.

**Activity:** `leave_activity_history` → `submitted`.

---

## Confirm (all requirements approved)

### Paid portion — `leave_confirmed`

**When:** All `leave_approval_requirement` rows satisfied → `status` → `confirmed`, `confirmed_at` set.

**Effect (paid days only):**

```
available_delta = 0
reserved_delta  = −paid_days
reason          = leave_confirmed
```

**Example:** After confirm → `available = 13`, `reserved = 0`. The 2 days left `available` at file time (they are consumed for reporting via `leave_info`, not a third bucket).

**Activity:** `leave_confirmed`.

### LWOP only — no transaction

**When:** `paid_days = 0`, `lwop_days = duration`, all requirements approved.

- Update `leave_info` → `confirmed`.
- **Do not** insert `leave_balance_transaction`.
- **Activity:** `leave_confirmed`.

---

## Reject (approver)

**When:** Approver rejects (see `APPROVAL_RULES.md`).

1. Insert `leave_approval_history` (`is_approved = false`).
2. `leave_info.status` → `rejected`.
3. If `paid_days > 0` was reserved:

```
available_delta = +paid_days
reserved_delta  = −paid_days
reason          = leave_reservation_released
```

4. **Activity:** `rejected`.

No further approver actions. Leave is closed.

---

## Cancel (employee)

**When:** Employee cancels while `pending_approval`.

1. `cancellation_reason` required.
2. `leave_info.status` → `cancelled`.
3. If reserved paid days exist → same transaction as reject:

```
reason = leave_reservation_released
```

4. **Activity:** `cancelled`.
5. **No** `leave_approval_history` row.

---

## Modify while pending

| Situation | Balance action |
|-----------|----------------|
| No requirement satisfied yet | May edit dates/duration. If `paid_days` changes: `leave_reservation_released` then `leave_reserved` for the new amount. **Activity:** `modified`. |
| At least one requirement satisfied | Employee must **cancel** (release reserve) and **submit a new** request. |

---

## LWOP at file time

**When:** Not enough `available` paid credits for the full request (product path: allow with LWOP notice). **Does not apply to PTB or EL** — duration > remaining credits is **422**, not unpaid. Extra days after remaining credits must be a **separate VL** (that VL may be LWOP). **LL is always fully unpaid** by type (not because credits ran out).

**At submit:**

- Set `lwop_days` + `paid_days` so they sum to `duration`.
- **`leave_reserved`** only for `paid_days` (if any).
- If fully LWOP: **no** reserve transaction.

**At confirm:** No balance transaction (see above).

**Example — fully LWOP, 2 days:**

| Stage | available | reserved | leave_info |
|-------|-----------|----------|------------|
| File | 0 | 0 | `paid_days=0`, `lwop_days=2` |
| Confirmed | 0 | 0 | `status=confirmed` |

---

## Mixed paid + LWOP

**Example:** `duration = 2`, `paid_days = 1`, `lwop_days = 1`

| Event | available | reserved |
|-------|-----------|----------|
| File (`leave_reserved` ×1) | −1 | +1 |
| Confirm (`leave_confirmed` ×1) | 0 | −1 |

`lwop_days = 1` stays on `leave_info` only.

---

## VL → SL conversion

After admin approves `leave_conversion` (see `APPROVAL_RULES.md`).

**Approved — two transactions in one DB transaction:**

1. **`vl_to_sl_restore`** — restore VL paid days from the original confirmation:

```
leave_type_id   = VL
available_delta = +paid_days (from original leave)
reserved_delta  = 0
```

2. **`vl_to_sl_deduct`** — deduct SL credits:

```
leave_type_id   = SL
available_delta = −paid_days (same duration equivalent)
reserved_delta  = 0
```

3. Update `leave_info.leave_type_id` → SL, `status` → `converted_to_sl`.

**Activity:** `conversion_approved`.

**Rejected conversion:** No balance changes. **Activity:** `conversion_rejected`.

---

## Undo confirmed leave — `leave_reversed`

**When:** Admin reverses a previously confirmed paid leave.

Reverse the net effect of `leave_confirmed` for that request’s `paid_days`:

```
available_delta = +paid_days
reserved_delta  = 0   (or restore to reserved if policy requires — default: return to available)
reason          = leave_reversed
```

Also set `leave_info.status` appropriately (per admin workflow).

---

## End-to-end example (paid VL)

Employee: `available = 15`, `reserved = 0`. Files **2-day VL**, fully paid.

| Step | status | Transaction | available | reserved |
|------|--------|-------------|-----------|----------|
| Submit | `pending_approval` | `leave_reserved` | 13 | 2 |
| Approver accepts | `confirmed` | `leave_confirmed` | 13 | 0 |

To report “2 days VL taken” → read this `leave_info` row (`duration` / `paid_days`), not `leave_balance`.

---

## Transaction quick reference

| reason | available_delta | reserved_delta | leave_id |
|--------|-----------------|----------------|----------|
| `initial_balance` | +N | 0 | NULL |
| `annual_entitlement` | +N | 0 | NULL |
| `monthly_accrual` | +1.25 | 0 | NULL |
| `leave_reserved` | −paid_days | +paid_days | set |
| `leave_confirmed` | 0 | −paid_days | set |
| `leave_reservation_released` | +paid_days | −paid_days | set |
| `vl_to_sl_restore` | +N (VL) | 0 | set |
| `vl_to_sl_deduct` | −N (SL) | 0 | set |
| `leave_reversed` | +paid_days | 0 | set |
| `manual_adjustment` | varies | varies | optional |

---

## Activity vs ledger

| Event | `leave_activity_history` | `leave_balance_transaction` |
|-------|--------------------------|-----------------------------|
| Submit (paid) | `submitted` | `leave_reserved` |
| Submit (full LWOP) | `submitted` | — |
| Edit (no approval yet) | `modified` | release + re-reserve if amount changed |
| Confirm (paid) | `leave_confirmed` | `leave_confirmed` |
| Confirm (LWOP) | `leave_confirmed` | — |
| Approver reject | `rejected` | `leave_reservation_released` |
| Employee cancel | `cancelled` | `leave_reservation_released` |
| Conversion approved | `conversion_approved` | `vl_to_sl_restore`, `vl_to_sl_deduct` |
| Admin annual VL/SL grant | `annual_credits_distributed` (one row per type; summarized) | `annual_entitlement` |
| Admin newly regularized grant | `regularization_credits_distributed` (one row per type; summarized) | `regularization_entitlement` |
| Admin contractual VL/SL grant | `contractual_credits_distributed` (one row per type; summarized) | `contractual_entitlement` |
| Admin manual available correction | `credits_adjusted` (solo employee) | `manual_adjustment` |

Reserved credit release is **only** in the ledger (`leave_reservation_released`), not a separate activity type.

---

## Laravel services

```
LeaveBalanceService
  applyInitialBalance(empId, leaveTypeId, amount)
  applyAnnualEntitlement(empId, leaveTypeId, amount, remarks)
  applyRegularizationEntitlement(empId, leaveTypeId, amount, remarks)
  applyContractualEntitlement(empId, leaveTypeId, amount, remarks)
  applyMonthlyAccrual(empId)
  reserve(LeaveInfo $leave)
  confirm(LeaveInfo $leave)
  releaseReservation(LeaveInfo $leave, reason remarks)
  applyConversion(LeaveConversion $conversion)
  reverseConfirmedLeave(LeaveInfo $leave)
  applyManualAdjustment(empId, leaveTypeId, availableDelta, remarks)

LeaveFinalizationService
  finalizeIfApproved(LeaveInfo $leave)   // calls confirm; skips ledger if full LWOP
```

**Guards:**

- Never update `leave_balance` without a ledger row (except LWOP confirm).
- Use one `DB::transaction()` per user action.
- Validate `paid_days + lwop_days = duration` before reserve and confirm.
