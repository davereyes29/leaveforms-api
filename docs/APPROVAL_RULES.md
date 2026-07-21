# Approval Rules (Revamp)

Who must approve a leave request, how decisions are recorded, and what happens next.

**Schema reference:** `SCHEMA.md`  
**Balance side effects:** `BALANCE_FLOW.md`

---

## Three layers

| Layer | Table | Purpose |
|-------|-------|---------|
| **Requirements** | `leave_approval_requirement` | What must be satisfied — snapshotted at **submit** |
| **Decisions** | `leave_approval_history` | What an approver did — **one row per requirement** |
| **Activity** | `leave_activity_history` | Broader audit (submit, cancel, reject, conversion, …) |

Requirements are frozen at submit. They do **not** change if the employee transfers groups or `formspic` is updated later.

---

## Roles (P1–P4)

From **kdtphdb `formspic.fldRole`** at submit time:

| Level | Title | `approval_level` | `approver_type` |
|-------|-------|------------------|-----------------|
| P1 | Leader/s | 1 | `leader` |
| P2 | Assistant Manager | 2 | `am` |
| P3 | Department Manager | 3 | `dm` |
| P4 | Senior Manager | 4 | `sm` |
| — | President | NULL | `president` |
| — | Admin | NULL | `admin` |

**Department scope:** Use the filer’s **primary group** (`employee_list.group_id`) matched against PIC rows whose `formspic.fldGroups` contains that group code.

**President fallback:** If no PIC matches the filer’s group, route to President (legacy parity).

---

## Requirement snapshot (`leave_approval_requirement`)

Created when the request is **submitted**, before approvers are notified.

Each row uses **exactly one** of:

| Type | `allowed_levels` | `required_approver_type` |
|------|------------------|--------------------------|
| Manager-level | JSON array (not null) | NULL |
| Special approver | NULL | `president` or `admin` |

### `allowed_levels` meanings

| Value | Meaning |
|-------|---------|
| `[1, 2, 3, 4]` | One approval from P1, P2, P3, or P4 |
| `[2, 3, 4]` | One approval from P2, P3, or P4 |
| `[3, 4]` | One approval from P3 or P4 |
| `[4]` | P4 specifically |

### `required_approver_type` meanings

| Value | Meaning |
|-------|---------|
| `president` | President must approve |
| `admin` | Admin must approve (PTB, maternity, emergency, etc.) |

---

## Required approvals by filer role (VL)

Determine the filer’s PIC role from `formspic` (no row = regular employee).

**One approval is enough.** The leave is confirmed as soon as any designated approver accepts. Submit emails go to the same designated set.

| Filer | Snapshot (`display_order` 1) | Who may approve / who is emailed |
|-------|------------------------------|----------------------------------|
| **Regular employee** | `[1, 2, 3, 4]` | P1, P2, P3, or P4 |
| **P1** | `[2, 3, 4]` | P2, P3, or P4 |
| **P2** | `[3, 4]` | P3 or P4 |
| **P3** | `[4]` | P4 |
| **P4** | `required_approver_type = president` | President |

### Examples

**Regular employee** — one row:

| display_order | allowed_levels | required_approver_type |
|---------------|----------------|------------------------|
| 1 | `[1, 2, 3, 4]` | NULL |

**P4 employee** — one row:

| display_order | allowed_levels | required_approver_type |
|---------------|----------------|------------------------|
| 1 | NULL | `president` |

---

## Who can act on a requirement

At action time, an approver must:

1. Be in `formspic` for the filer’s primary group (or be President / Admin as required).
2. Match the requirement:
   - **Manager-level:** `approval_level` is in `allowed_levels`.
   - **President:** `required_approver_type = president`.
   - **Admin:** `required_approver_type = admin`.

**First actor wins:** `leave_approval_history` has **`UNIQUE (requirement_id)`**. The first approver who acts on that requirement inserts the only row. No updates, no second row.

---

## Approve path

When an approver **approves** a requirement:

1. Insert `leave_approval_history`:
   - `requirement_id`, `approver_emp_id`, `approval_level`, `approver_type`
   - `is_approved = true`
2. Set `leave_approval_requirement.is_satisfied = true`, `satisfied_at = now`.
3. Check remaining requirements:
   - **Some still open** → `leave_info.status = partially_approved`
   - **All satisfied** → finalize (below)

### Finalize (all requirements satisfied)

1. `leave_info.status = confirmed`
2. `confirmed_at = now`
3. Balance: see `BALANCE_FLOW.md` (`leave_confirmed` for paid days; no ledger row for full LWOP).
4. `leave_activity_history` → `leave_confirmed`

---

## Reject path

When an approver **rejects** on any requirement:

1. Insert `leave_approval_history`:
   - `is_approved = false`
   - **`remarks` required**
2. `leave_info.status = rejected` — **entire leave is closed**
3. Release reserved credits → `leave_reservation_released` (`BALANCE_FLOW.md`)
4. `leave_activity_history` → `rejected`

**No further approver actions.** Example: if P1 rejects on a `[1,2,3,4]` requirement, no other approver can act afterward — the leave is already `rejected`.

---

## Employee cancel

While `status` is `pending_approval` or `partially_approved`:

1. Employee submits **`cancellation_reason`** (required).
2. `leave_info.status = cancelled`
3. Release reserved credits → `leave_reservation_released`
4. `leave_activity_history` → `cancelled`
5. **No** `leave_approval_history` row.

Prior approval rows, attachments, and activity history **remain** for audit.

---

## Modify while pending

| Situation | Allowed? |
|-----------|----------|
| **No** requirement satisfied yet | Yes — edit dates/details. If `paid_days` changes, release and re-reserve (`BALANCE_FLOW.md`). Activity: `modified`. |
| **At least one** requirement satisfied | No — employee must **cancel** and **submit a new** request. |

---

## Leave type workflows

### Vacation Leave (VL) — including unexpected sickness

Unexpected sickness is **not** filed as SL.

1. Employee or **Group Leader** files **VL** (`filed_by_emp_id` may differ from `emp_id`).
2. Snapshot requirements + reserve paid credits (or LWOP path).
3. Approvers act per matrix above.
4. When all requirements satisfied → **confirm immediately**. **No admin step** on the original VL request.
5. Quarterly absence warnings (7–8 / 9 days per quarter) are informational only — do not block approval.

### Sick Leave (SL) — after conversion only

`SL` as a leave type is used **after** an approved VL → SL conversion. Do **not** file unexpected sickness directly as SL.

See **VL → SL conversion** below.

### Other leave types (PTB, maternity, emergency, …)

Use the same requirement snapshot pattern. Include a requirement row with `required_approver_type = admin` where policy requires admin approval on the **original** request.

> **Stakeholder confirmation required:** Exact requirement sets per leave type (EL, ML, PL, PTB, LL) beyond “admin when needed.”

---

## VL → SL conversion (unexpected sickness)

After the employee **returns to work**:

1. Original absence must be **confirmed VL** (`status = confirmed`).
2. Employee requests conversion + uploads med cert → `leave_conversion` (`status = pending_review`).
3. `leave_activity_history` → `conversion_requested`.
4. **Admin** reviews (`leave_conversion.reviewed_by_emp_id`, `reviewed_at`, `remarks`).

**If approved:**

- `vl_to_sl_restore` + `vl_to_sl_deduct` (`BALANCE_FLOW.md`)
- `leave_info.leave_type_id` → SL
- `leave_info.status` → `converted_to_sl`
- `leave_conversion.status` → `approved`
- Activity → `conversion_approved`

**If rejected:**

- No balance changes
- Leave stays **VL** / `confirmed`
- `leave_conversion.status` → `rejected`
- Activity → `conversion_rejected`

Admin review lives on **`leave_conversion`**, not on the original VL approval chain.

---

## Status summary

| Status | Meaning |
|--------|---------|
| `pending_approval` | Submitted; no requirement satisfied yet |
| `partially_approved` | At least one requirement satisfied; not all |
| `confirmed` | All requirements satisfied; leave finalized |
| `rejected` | Approver rejected; leave closed |
| `cancelled` | Employee cancelled; leave closed |
| `converted_to_sl` | VL → SL conversion approved |

---

## End-to-end example — regular employee, 2-day VL

| Step | status | Requirements |
|------|--------|--------------|
| Submit | `pending_approval` | Req 1 `[1,2,3,4]` open (P1–P4 notified) |
| P3 approves | `confirmed` | Req 1 satisfied → finalize |

If any designated approver **rejects** → `rejected` immediately.

---

## Manager dashboard visibility

Managers in `formspic` see leave for employees whose **primary group** is in their `fldGroups`.

> **Stakeholder confirmation required:** Report access and special group rules (e.g. legacy KRM / `checkTier` lists) if they differ from this default.

---

## Laravel implementation (suggested)

```
app/Services/Leave/
  LeaveApprovalPolicy.php      // build requirement rows from filer role
  LeaveApprovalService.php     // record approve/reject; check canAct
  LeaveFinalizationService.php // confirm + call LeaveBalanceService
  LeaveCancellationService.php // employee cancel
  LeaveSubmissionService.php   // submit + snapshot requirements + reserve
```

Controllers stay thin: validate input → call service → return JSON.

---

## Related docs

- Requirement columns and enums → `SCHEMA.md`
- Reserve / confirm / release → `BALANCE_FLOW.md`
- Legacy app comparison → `LEGACY_NOTES.md`
