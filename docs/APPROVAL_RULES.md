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

**Department scope:** Use the filer’s **primary group** (`employee_list.group_id`, or `LEAVE_EMPLOYEE_GROUP_OVERRIDES` when set) matched against PIC rows whose `formspic.fldGroups` contains that group code.

**Leaveforms group override:** `LEAVE_EMPLOYEE_GROUP_OVERRIDES=emp_id:group_id` (comma-separated). Example: employee `212` is stored in Ship (`group_id` 22) but is treated as Systems (`16`) for routing, `GET /approvals`, `GET /team/calendar`, on-behalf members, and `GET /me` group. Does not write to `kdtphdb_new`.

**President fallback:** If no PIC matches the filer’s group, route to President (legacy parity).

---

## Filing on behalf of another employee

When `POST /api/leaves` includes `emp_id` different from the logged-in user, `LeaveOnBehalfAuthorizationService` decides if that is allowed.

**Group members dropdown:** `GET /api/groups/{group}/members` returns only people this PIC may file for in that group (same matrix and group scope as below). `{group}` is `group_list.id` from `GET /me` `role.pic_groups`.

**Group scope (always):** the person on leave’s **primary** group abbreviation must appear in the filer’s `formspic.fldGroups`.

**Role matrix** (who may submit for whom):

| Person on leave (`emp_id`) | Who may file (`filed_by_emp_id`) |
|----------------------------|----------------------------------|
| Regular (no `formspic` role 1–4) | P1, P2 |
| P1 | P2, P3, P4 |
| P2 | P3, P4 |
| P3 | P4 |
| P4 | Not implemented yet (President later) |

Approval requirements are still snapshotted from the **person on leave**, not the person who clicked submit.

**VL 3+ workday notice** does not apply to this path. Only the original filer may edit the pending request afterward.

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

## Required approvals (SL, PTB, EL, LL, ML, and PL)

Regardless of filer PIC role: **one** snapshot row with `required_approver_type = admin`. Admin is the only person who may approve or reject. Designated VL-matrix PICs (table above) are **CC'd** on submit and cancel mail; they are not on the requirement.

**Any employee** — one row:

| display_order | allowed_levels | required_approver_type |
|---------------|----------------|------------------------|
| 1 | NULL | `admin` |

---

## Who can act on a requirement

At action time, an approver must:

1. Be in `formspic` for the filer’s primary group (or be President / Admin as required).
2. Match the requirement:
   - **Manager-level:** `approval_level` is in `allowed_levels`.
   - **President:** `required_approver_type = president`.
   - **Admin:** `required_approver_type = admin`.

**First actor wins:** `leave_approval_history` has **`UNIQUE (requirement_id)`**. The first approver who acts on that requirement inserts the only row. No updates, no second row.

### API display: `eligible_approvers` on `GET /leaves/{id}`

Stage-based list of who is responsible (still shown after someone acts). Built by `LeaveEligibleApproverService`:

| Leave status | List contents |
|--------------|---------------|
| `pending_approval`, `confirmed`, `rejected`, `cancelled` | Match snapshotted requirements (formspic + group + levels, or president/admin when required) — ignores `is_satisfied` |
| `pending_conversion`, `converted_to_sl` | Admin only (`LEAVE_ADMIN_EMP_ID`) |

Who actually approved/rejected remains on `approval_requirements[].history` (leave) or conversion review fields.

---

## Approve path

When an approver **approves** a requirement:

1. Insert `leave_approval_history`:
   - `requirement_id`, `approver_emp_id`, `approval_level`, `approver_type`
   - `is_approved = true`
2. Set `leave_approval_requirement.is_satisfied = true`, `satisfied_at = now`.
3. Check remaining requirements:
   - **All satisfied** → finalize (below)
   - (Each filable type snapshots a single requirement, so one approve always finalizes.)

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
5. Email the employee on leave (and the filer when on-behalf) via `LeaveRejectedMail`

**No further approver actions.** Example: if P1 rejects on a `[1,2,3,4]` requirement, no other approver can act afterward — the leave is already `rejected`.

---

## Employee cancel

While `status` is `pending_approval`:

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

**Who may edit:** self-filed leave → the employee. On-behalf (File Leave for Members) → **only the original filer** (`filed_by_emp_id`). The member cannot change dates, reason, or attachments. Cancel stays with the employee on leave.

---

## Leave type workflows

### Vacation Leave (VL)

Unexpected sickness **without** a medical certificate is filed as VL, then converted to SL after return (see **VL → SL conversion** below). If a medical certificate is already available, file **SL** directly.

1. Employee or an allowed PIC files **VL** (`filed_by_emp_id` may differ from `emp_id` — see **Filing on behalf** above).
2. **Advance notice (self-file only):** VL of **3.00 or more workdays** must have `start_date` ≥ today + **1 calendar month** (Asia/Manila, `addMonthsNoOverflow`). Shorter VL is not gated. **On-behalf PIC filing skips this** (any PIC already allowed to file for that member). Example: filed 1 Sep → earliest self-file 3-day start is 1 Oct.
3. Snapshot requirements + reserve paid **VL** credits (or LWOP path).
4. Approvers act per matrix above.
5. When all requirements satisfied → **confirm immediately**. **No admin step** on the original VL request.
6. Quarterly absence warnings (7–8 / 9 days per quarter) are informational only — do not block approval.

### Sick Leave (SL) — direct filing

File SL when the employee already has a medical certificate. Overlap still applies: dates occupied by VL (or any other open leave) cannot be filed as a second request.

1. Employee or an allowed PIC files **SL** with 1–5 medical certificates (`leave_attachment[]`).
2. Snapshot **one** requirement: `required_approver_type = admin`. Reserve paid **SL** credits (or LWOP path).
3. Email: **To** admin, **CC** designated VL-matrix approvers (P1–P4 / president). Med cert attached. PICs do not approve.
4. Admin approves or rejects on the original leave (`POST /leaves/{id}/approve` or `reject`). One admin approval confirms.
5. Direct-filed SL is never converted (`leave_conversion` is VL-only).

If VL for those dates is still `pending_approval`, cancel it first, then file SL. If VL is already `confirmed`, convert it instead.

### PTB Leave (Pulmonary Tuberculosis)

Treat like direct SL for filing and approval, with different duration and credit rules.

1. Employee or an allowed PIC files **PTB** with 1–5 medical certificates (`leave_attachment[]`). `day_type` must be `full` (`half_am` / `half_pm` → **422**).
2. Duration is **inclusive calendar days** (weekends and holidays count). Friday–Monday = **4.00**.
3. **No LWOP.** If duration > remaining PTB credits → **422**. Example: 40 remaining, 50-day request is rejected; file 40 PTB then a **separate VL** for the rest (that VL may be LWOP). Multiple PTB filings are allowed until credits are gone. Zero credits → cannot file (admin can grant via newly-regularized **180** or `POST /admin/balances`).
4. Snapshot **one** requirement: `required_approver_type = admin`. Reserve paid **PTB** credits (`paid_days = duration`, `lwop_days = 0`).
5. Email: **To** admin, **CC** designated VL-matrix approvers. Med cert attached. PICs do not approve.
6. Admin approves or rejects on the original leave. One admin approval confirms.
7. Team calendar `dates` include weekends and holidays (unlike VL/SL/EL).

### Emergency Leave (EL)

Treat like PTB for filing and approval (attachment + admin + no LWOP + multiple filings), with **workday** duration like VL/SL.

1. Employee or an allowed PIC files **EL** with 1–5 supporting documents (`leave_attachment[]` — not necessarily a medical certificate). `day_type` must be `full` (`half_am` / `half_pm` → **422**).
2. Duration is **workdays** (weekends and holidays skipped). Typical remaining cap is **5.00**.
3. **No LWOP.** If duration > remaining EL credits → **422**. Multiple EL filings are allowed until credits are gone (e.g. 2 days next week, 3 the week after). Zero credits → cannot file (admin can grant via `POST /admin/balances`; EL is given at hiring, not on the newly-regularized screen).
4. Snapshot **one** requirement: `required_approver_type = admin`. Reserve paid **EL** credits (`paid_days = duration`, `lwop_days = 0`).
5. Email: **To** admin, **CC** designated VL-matrix approvers. Supporting document attached. PICs do not approve.
6. Admin approves or rejects on the original leave. One admin approval confirms.
7. Team calendar `dates` are workdays only (same as VL/SL).

### Long Leave (LL)

Unpaid leave arranged offline with admin and superior before filing.

1. Employee or an allowed PIC files **LL** as multipart (`day_type=full`, 1–5 `leave_attachment[]` — supporting documents).
2. Duration is **inclusive calendar days**, bounded by **calendar months**: end ≥ start + 1 month − 1 day and ≤ start + 3 months − 1 day (e.g. 15 Sep → 14 Oct through 14 Dec).
3. Always unpaid: `paid_days = 0`, `lwop_days = duration`. **No** credit reserve.
4. Requires **5 complete years** from `date_hired` as of the file date (Asia/Manila). Missing hire date or under 5 years → **422**.
5. Next LL only if `start_date` ≥ last **confirmed** LL `end_date` + **10 years**. Rejected/cancelled do not count. Another `pending_approval` LL also blocks a new file.
6. Snapshot **one** requirement: `required_approver_type = admin`. Email: **To** admin, **CC** designated VL-matrix approvers. Supporting document attached. PICs do not approve.
7. Admin approves or rejects on the original leave. One admin approval confirms.
8. Team calendar `dates` include weekends and holidays (same as PTB).

### Maternity Leave (ML)

Credits are granted when the request is filed. No attachment (forms are handled offline with admin).

1. Female employee or an allowed PIC files **ML** as JSON (`day_type=full`, no `leave_attachment`). Male → **422**.
2. Duration is **exactly 105 inclusive calendar days** (end = start + 104 days). FE picks start and sends the computed end.
3. On submit: grant **105** ML (`parental_entitlement`) then reserve (`paid_days = 105`, `lwop_days = 0`). This is paid ML, not LWOP.
4. Reject/cancel: release reserve then reverse the grant (`parental_entitlement_reversed`) so leftover ML credits do not remain.
5. Unlimited confirmed MLs (105 days again per later child). Another `pending_approval` ML blocks a new file.
6. Snapshot **one** requirement: `required_approver_type = admin`. Email: **To** admin, **CC** designated VL-matrix approvers (no file).
7. Admin approves or rejects. One admin approval confirms (`leave_confirmed`).
8. Team calendar `dates` include weekends and holidays (same as PTB).

### Paternity Leave (PL)

Same grant-on-submit pattern as ML, with a fixed workday length and a lifetime cap.

1. Male employee or an allowed PIC files **PL** as multipart (`day_type=full`, 1–5 `leave_attachment[]` — Paternity Notification Form). Female → **422**.
2. Duration is **exactly 7 workdays** in one request. Start must be a workday. End must be the 7th workday. Cannot file 1–2 days.
3. On submit: grant **7** PL then reserve. Reject/cancel reverses the grant.
4. At most **4 confirmed** PL lifetime (stand-in for first 4 deliveries). Rejected/cancelled do not count. Another pending PL blocks a new file.
5. Snapshot **one** admin requirement. Email: **To** admin, **CC** PICs. Paternity Notification Form attached.
6. Team calendar `dates` are workdays only (same as VL/SL/EL).

---

## VL → SL conversion (unexpected sickness)

After the employee **returns to work**:

1. Original absence must be **confirmed VL** (`status = confirmed`).
2. Employee requests conversion + uploads 1–5 med certs + **conversion reason** → `leave_conversion` (`status = pending_review`) and `leave_info.status` → `pending_conversion`. While pending, the employee may add or remove individual files (at least one must remain).
3. `leave_activity_history` → `conversion_requested` (remarks = employee reason).
4. **Admin** reviews (`leave_conversion.reason` is the employee’s diagnosis; `reviewed_by_emp_id`, `reviewed_at`, `remarks` are the admin decision).

**If approved:**

- `vl_to_sl_restore` + `vl_to_sl_deduct` (`BALANCE_FLOW.md`)
- `leave_info.leave_type_id` → SL
- `leave_info.status` → `converted_to_sl`
- `leave_conversion.status` → `approved`
- Activity → `conversion_approved`

**If rejected:**

- No balance changes
- Leave stays **VL**; `leave_info.status` → `confirmed`
- `leave_conversion.status` → `rejected`
- Activity → `conversion_rejected`

Admin review lives on **`leave_conversion`**, not on the original VL approval chain. Employee list filters use `leave_info.status = pending_conversion`.

---

## Status summary

| Status | Meaning |
|--------|---------|
| `pending_approval` | Submitted; awaiting the designated approver (PIC for VL, admin for all other filable types) |
| `confirmed` | Approver accepted; leave finalized |
| `pending_conversion` | VL → SL conversion requested; awaiting admin review |
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
  LeaveApprovalPolicy.php      // VL matrix or SL admin requirement
  LeaveApprovalService.php     // record approve/reject; check canAct
  LeaveFinalizationService.php // confirm + call LeaveBalanceService
  LeaveCancellationService.php // employee cancel
  LeaveSubmissionService.php   // submit + snapshot requirements + reserve
  LeaveNotificationService.php // VL To PICs; SL/conversion To admin + CC PICs
```

Controllers stay thin: validate input → call service → return JSON.

---

## Related docs

- Requirement columns and enums → `SCHEMA.md`
- Reserve / confirm / release → `BALANCE_FLOW.md`
- Legacy app comparison → `LEGACY_NOTES.md`
