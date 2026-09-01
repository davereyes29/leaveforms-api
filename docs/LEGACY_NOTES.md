# Legacy Notes

Reference for the **current production** app (`leaveforms` — PHP + jQuery). Use this to compare old behavior with the **revamp** docs.

**Revamp specification (build from these):**

- `SCHEMA.md`
- `BALANCE_FLOW.md`
- `APPROVAL_RULES.md`

---

## Two apps, two databases

| | Legacy | Revamp |
|---|--------|--------|
| **Code** | `leaveforms/` — PHP, jQuery, `ajax/*.php` | Laravel API + React TS |
| **Leave DB** | `formsdb` (old tables) | `formsdb` (new schema in `SCHEMA.md`) |
| **Employee DB** | `kdtphdb` | `kdtphdb` (read-only) |

Do not run old and new migrations on the same `formsdb` without a cutover plan.

---

## What changed (revamp vs legacy)

| Topic | Legacy | Revamp |
|-------|--------|--------|
| **Unexpected sickness** | Could file SL directly | File **VL** first → **VL→SL conversion** after return **or** file **SL** directly when a med cert is already available |
| **HR / admin on original leave** | HR confirmed most leave (`confirmationAccepted.php`, `l_remarks`) | VL confirms when leaders approve; **admin** on `leave_conversion` or `required_approver_type = admin` for other types |
| **Balance** | `leave_confirmation` (`lc_vl`, `lc_sl`, …) | `leave_balance` (`available`, `reserved`) + ledger |
| **Consumed days** | Mixed into balance / `l_lwop` bugs | Not on balance row; sum **confirmed** `leave_info` for reports |
| **LWOP** | `l_lwop` column | `leave_info.lwop_days`; no bucket change on confirm |
| **Approval state** | Serialized `l_status` on `leave_info` | `leave_approval_requirement` + `leave_approval_history` |
| **Approval rules at submit** | Live `formspic` matching | **Snapshotted** requirements |
| **Reject** | Per-approver in `leave_accept` | One row per requirement; **any reject closes** entire leave |
| **Employee cancel** | Unclear / varied | `cancelled` + `cancellation_reason` + release reserve |
| **Edit while pending** | Allowed in places | Only if **no** requirement satisfied; else cancel + refile |
| **P2 filer rule** | In code (`getRequired`, `checkTier`) | Documented in `APPROVAL_RULES.md` |

---

## kdtphdb (read-only in revamp)

| Constraint | Detail |
|------------|--------|
| **Do not alter schema** | Per project decision |
| **Employees** | `employee_list`, `group_list`, `employee_group` |
| **Primary group** | `employee_list.group_id` — used for approver routing at **submit** (leaveforms may override via `LEAVE_EMPLOYEE_GROUP_OVERRIDES`) |
| **Extra groups** | `employee_group` — not used for approval snapshot |
| **Approvers** | `formspic` (`fldRole` 1–4, serialized `fldGroups`) |
| **Holidays** | `kdtholiday` |
| **Login** | `kdtlogin` + cookie `userID` |

**`formspic.fldGroups`:** PHP serialized array. Another app still uses this format — **do not normalize** in kdtphdb. `unserialize()` in Laravel when reading.

**President:** Legacy `$kdtPresident` in `dbconnectkdtph.php` → revamp config e.g. `leave.president_emp_id`.

**Fallback:** No PIC for group → President (`ajax/getApprover.php`).

---

## Authentication (legacy)

1. `Includes/checkLogin.php` reads cookie `userID`
2. Lookup `kdtlogin.fldUserHash` → employee number
3. Load `emp_prof` / `employee_list`
4. Redirect to `/KDTPortalLogin` if missing

**Revamp:** Reuse portal session (Sanctum / shared cookie — decide with infra).

---

## Legacy tables → revamp mapping

| Legacy (`formsdb`) | Revamp |
|--------------------|--------|
| `leave_info` | `leave_info` |
| `leave_accept` | `leave_approval_history` |
| — | `leave_approval_requirement` (new) |
| `leave_confirmation` | `leave_balance` + `leave_balance_transaction` |
| Serialized `l_status` | Requirements + history tables |
| `l_remarks` (HR confirm) | Dropped for VL; admin on conversion / admin requirements |
| `l_lwop` | `leave_info.lwop_days` |
| `l_dtype` | `leave_info.day_type` |
| `l_filedBy` | `filed_by_emp_id` |
| Informal VL→SL | `leave_conversion` |
| — | `leave_activity_history` (new) |

### Leave type codes

| Legacy `l_type` | Revamp `leave_type.code` | Notes |
|-----------------|--------------------------|-------|
| `vleave` | `VL` | Primary filing; includes unexpected sickness |
| `sleave` | `SL` | Direct filing (med cert + admin) **or** after approved VL→SL conversion |
| `eleave` | `EL` | Direct filing (supporting document + admin). Workdays; full day; no LWOP |
| `mleave` | `ML` | Paid; admin; exactly 105 calendar days; grant on submit; female only; unlimited confirmed |
| `pleave` | `PL` | Paid; admin; exactly 7 workdays; grant on submit; male only; max 4 confirmed; Paternity Notification Form (1–5 files) |
| `ptbleave` | `PTB` | Direct filing (med cert + admin). Calendar days; full day; no LWOP |
| `lleave` | `LL` | Unpaid; admin; 1–3 calendar months; no overlapping dates; one pending LL at a time; supporting document (1–5 files) |

---

## Legacy code worth reading

| File | What to learn |
|------|----------------|
| `ajax/apply.php` | Submit payload, half-day / shift handling |
| `ajax/showCards.php` | Approval badges, P1–P4 display |
| `ajax/newDashboard.php` | Manager list, `checkTier`, `checkSamePIC` |
| `ajax/confirmationAccepted.php` | Old HR confirm + balance + workdays — **not** revamp VL flow |
| `ajax/getApprover.php` | President fallback |
| `ajax/countDuration.php` | Workday counting |
| `ajax/applyEmailNotif.php` | Notify approvers on submit |
| `ajax/getReportData.php` | Reports, LWOP display |
| `Includes/checkLogin.php` | Portal auth |
| `Includes/dbconnectkdtph.php` | President, dev IDs, IT lists |
| `js/main.js` | `adminCheck`, hardcoded HR emp `121` |

### Port logic (adapt to revamp)

- Multi-tier approval matrix → `APPROVAL_RULES.md`
- `isWorkDay()` / holidays → duration calculation in `BALANCE_FLOW.md`
- Email on submit / notify on approve-reject
- File on behalf of member → `filed_by_emp_id`
- Quarterly absence warnings (revamp slides; partial overlap in reports)

### Do not port

- HR confirms all VL (`confirmationAccepted.php` for VL)
- Serialized `l_status`
- `bulk_accept.php` batch HR confirm
- Hardcoded employee IDs for HR UI (`ConfirmCheck` emp `121`)
- Negative / wrong `l_duration` patterns

> **Stakeholder confirmation required:** Legacy special rules — KRM, `checkTier` department lists (`nonP2` / `nonP3` arrays), `access_report.php` / `access_count.php` access lists.

---

## Balance migration (legacy → revamp)

Legacy balances live in `leave_confirmation` (`lc_vl`, `lc_sl`, `lc_el`, …).

**Cutover approach:**

1. One-time script reads remaining credits per employee per type.
2. Insert `leave_balance` (`available` = legacy remaining, `reserved` = 0).
3. Insert `leave_balance_transaction` with `reason = initial_balance` per row.

No manual HR entry unless fixing exceptions.

Open pending legacy requests: cut over on a boundary date or re-file in revamp — plan separately.

Do not import corrupted negative `l_duration`; recalculate from dates if historical import is needed.

---

## Environment

**Legacy `.env`:** `DB1_*` → kdtphdb, `DB3_*` → formsdb.

**Revamp `.env` example:**

```env
DB_CONNECTION=mysql
DB_DATABASE=formsdb

KDT_PH_DB_HOST=127.0.0.1
KDT_PH_DB_DATABASE=kdtphdb
```

---

## Using these docs in Cursor

In the Laravel API repo:

```
@docs/SCHEMA.md
@docs/BALANCE_FLOW.md
@docs/APPROVAL_RULES.md
@docs/LEGACY_NOTES.md   ← compare / port only
```

Build new behavior from the first three files. Open this file when you need to understand **why** something existed in the old app or **what** to migrate.
