# Balance Flow (Revamp)

How paid leave credits move between **`available`** and **`reserved`**, and when ledger rows are written.

**Schema reference:** `SCHEMA.md`  
**Approval side effects:** `APPROVAL_RULES.md`

---

## Two buckets

| Bucket | Meaning |
|--------|---------|
| **available** | Paid credits the employee can still use. Grows via accrual. |
| **reserved** | Paid credits held for `pending_approval` / `partially_approved` requests. |

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

**When:** Yearly grant for eligible employees.

**Amount (confirmed):**

| Tenure | VL | SL |
|--------|----|----|
| Less than 3 years | +15 | +15 |
| 3 years or more | +15 | +18 |

Write **one transaction per leave type** (VL row and SL row).

```
available_delta = +15 (or +18 for SL)
reserved_delta  = 0
reason          = annual_entitlement
leave_id        = NULL
```

---

### `monthly_accrual`

**When:** Employee becomes regular mid-year and did **not** receive the full `annual_entitlement` for that period.

**Amount:** +1.25 VL and +1.25 SL per run (one transaction per leave type).

```
available_delta = +1.25
reserved_delta  = 0
reason          = monthly_accrual
leave_id        = NULL
```

---

### `manual_adjustment`

**When:** HR corrects a balance.

Set deltas as needed. Always record `remarks`.

---

## Filing leave (paid credits)

### Step 1 — Calculate days

From `start_date`, `end_date`, `day_type`:

- Count workdays (exclude weekends/holidays per `kdtholiday`).
- Half-day → `0.5`.

Set `duration`. Split into `paid_days` and `lwop_days` (see LWOP section).

### Step 2 — Reserve (`leave_reserved`)

**When:** Submit with `paid_days > 0` and `status` → `pending_approval`.

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

**When:** Employee cancels while `pending_approval` or `partially_approved`.

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

**When:** Not enough `available` paid credits for the full request (product path: allow with LWOP notice).

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
| 1st approver | `partially_approved` | — | 13 | 2 |
| All approvers | `confirmed` | `leave_confirmed` | 13 | 0 |

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

Reserved credit release is **only** in the ledger (`leave_reservation_released`), not a separate activity type.

---

## Laravel services

```
LeaveBalanceService
  applyInitialBalance(empId, leaveTypeId, amount)
  applyAnnualEntitlement(empId, tenure)
  applyMonthlyAccrual(empId)
  reserve(LeaveInfo $leave)
  confirm(LeaveInfo $leave)
  releaseReservation(LeaveInfo $leave, reason remarks)
  applyConversion(LeaveConversion $conversion)
  reverseConfirmedLeave(LeaveInfo $leave)
  manualAdjust(...)

LeaveFinalizationService
  finalizeIfApproved(LeaveInfo $leave)   // calls confirm; skips ledger if full LWOP
```

**Guards:**

- Never update `leave_balance` without a ledger row (except LWOP confirm).
- Use one `DB::transaction()` per user action.
- Validate `paid_days + lwop_days = duration` before reserve and confirm.
