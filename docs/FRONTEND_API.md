# Leave Forms API — frontend notes

Cheat sheet for wiring the React app to this Laravel API. Source of truth for routes: `routes/api.php`.

## Setup

| Item | Value |
|------|--------|
| Base URL | `{APP_URL}/api` (local default `http://localhost:8000/api`) |
| Auth | Portal cookie `userID` — send `credentials: 'include'` on every request |
| CORS | `FRONTEND_URL` in API `.env` must match the React origin (e.g. `http://localhost:5173`) |

Unauthenticated requests return **401**:

```json
{
  "message": "Unauthenticated.",
  "login_url": "http://localhost/KDTPortalLogin"
}
```

Redirect the browser to `login_url` when you get 401.

### Response shape

Laravel JSON resources wrap payloads:

- Collection: `{ "data": [ ... ] }` (lists may also include pagination meta)
- Single resource: `{ "data": { ... } }`

Validation failures return **422** with an `errors` object keyed by field name.

### Fetch example

```js
const API = import.meta.env.VITE_API_URL; // e.g. http://localhost:8000/api

const res = await fetch(`${API}/me`, {
  credentials: 'include',
  headers: { Accept: 'application/json' },
});

if (res.status === 401) {
  const body = await res.json();
  window.location.href = body.login_url;
  return;
}
```

---

## Enums (for UI labels)

| Field | Values |
|-------|--------|
| `day_type` | `full`, `half_am`, `half_pm` |
| Leave `status` | `pending_approval`, `confirmed`, `pending_conversion`, `rejected`, `cancelled`, `converted_to_sl` |
| Conversion `status` | `pending_review`, `approved`, `rejected` |
| `activity_type` | `submitted`, `modified`, `cancelled`, `rejected`, `attachment_added`, `leave_confirmed`, `conversion_requested`, `conversion_approved`, `conversion_rejected` |

**VL** does **not** accept `leave_attachment`. Conversion uploads use field name **`med_cert`**.

---

## Endpoints

### Health (no auth)

| Method | Path | Notes |
|--------|------|--------|
| GET | `/health` | DB connectivity check |

---

### Dev login (local only)

Bypasses KDT portal login while developing. **404 when `APP_ENV` is not `local`.**

Returns `fldUserHash` so the frontend can set the `userID` cookie itself. The cookie must be visible to the API origin (same host, or Vite proxy to `/api`).

| Method | Path | Body | Notes |
|--------|------|------|--------|
| POST | `/dev/login` | `{ "emp_id": 8 }` | Returns `userID` (= `kdtlogin.fldUserHash`) |

Example response:

```json
{
  "userID": "abc123hash...",
  "cookie": "userID"
}
```

```js
const res = await fetch(`${API}/dev/login`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ emp_id: 8 }),
});
const { userID, cookie } = await res.json();
document.cookie = `${cookie}=${encodeURIComponent(userID)}; path=/; SameSite=Lax`;
// then GET /me with credentials: 'include'
```

---

### Session / profile

| Method | Path | Notes |
|--------|------|--------|
| GET | `/me` | Current employee from portal cookie |

Example response:

```json
{
  "id": 8,
  "firstname": "Jane",
  "surname": "Doe",
  "full_name": "Jane Doe",
  "email": "jane@example.com",
  "username": "jdoe",
  "group_id": 3,
  "group": {
    "id": 3,
    "abbreviation": "SYS",
    "name": "Systems"
  },
  "date_hired": "2020-01-15",
  "role": {
    "approval_level": 2,
    "is_president": false,
    "is_admin": false,
    "pic_groups": ["SYS", "HR"]
  }
}
```

`role` comes from `formspic` + config (`LEAVE_PRESIDENT_EMP_ID`, `LEAVE_ADMIN_EMP_ID`):

| Field | Meaning |
|-------|---------|
| `approval_level` | `1`–`4` (P1–P4) from `formspic.fldRole`, or `null` if not a PIC |
| `is_president` | Employee id matches `LEAVE_PRESIDENT_EMP_ID` |
| `is_admin` | Employee id matches `LEAVE_ADMIN_EMP_ID` |
| `pic_groups` | Group abbreviations from `formspic.fldGroups` (empty if not a PIC) |

---

### Balances

| Method | Path | Query |
|--------|------|--------|
| GET | `/balances` | optional `?code=VL` (case-insensitive) |

Each item:

```json
{
  "leave_type_id": 1,
  "code": "VL",
  "name": "Vacation Leave",
  "available": "12.00",
  "reserved": "1.00"
}
```

---

### Activities (Activity Log)

Timeline of actions on leaves where **you** are the person on leave (`leave_info.emp_id`). Does **not** include leaves you only filed on behalf of someone else (unless you are also on leave), or leaves you only approved.

| Method | Path | Query |
|--------|------|--------|
| GET | `/activities` | optional `?code=VL`, `?activity_type=submitted`, `?sort=asc\|desc` (default `desc`), `?page=1`, `?per_page=15` (1–50, default 15) |

Same pagination as `GET /leaves` (Laravel length-aware): use `page` + `per_page`. Sorted by timestamp (`created_at`); default **newest first** (`sort=desc`). Balance Impact is derived from matching `leave_balance_transaction` rows for that leave.

**Response envelope** (paginated):

```json
{
  "data": [
    {
      "id": 12,
      "leave_id": 41,
      "timestamp": "2026-07-08T11:05:00+00:00",
      "activity_type": "submitted",
      "activity": "Leave request submitted",
      "leave_type": {
        "id": 1,
        "code": "VL",
        "name": "Vacation Leave"
      },
      "balance_impact": "3.0 VL reserved",
      "ledger": [
        {
          "id": 55,
          "reason": "leave_reserved",
          "leave_type_code": "VL",
          "available_delta": "-3.00",
          "reserved_delta": "3.00"
        }
      ],
      "performed_by": {
        "id": 8,
        "name": "Alex Employee"
      },
      "remarks": null
    }
  ],
  "links": {
    "first": "http://localhost:8000/api/activities?page=1",
    "last": "http://localhost:8000/api/activities?page=3",
    "prev": null,
    "next": "http://localhost:8000/api/activities?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 3,
    "per_page": 15,
    "to": 15,
    "total": 42
  }
}
```

| Field | Notes |
|-------|--------|
| `activity` | UI label (`leave_confirmed` → **"Leave request approved"**) |
| `balance_impact` | Display string from ledger, or **"No change"** when no matching rows (e.g. LWOP submit, conversion request) |
| `ledger` | Structured deltas paired to this activity; empty array when no change |
| `performed_by.name` | Display name — same key as `eligible_approvers[].name` on leave detail (`/me` still uses `full_name`) |
| `remarks` | Present on reject / cancel; otherwise usually `null` |

**`activity_type` values:** `submitted`, `modified`, `cancelled`, `rejected`, `attachment_added`, `leave_confirmed`, `conversion_requested`, `conversion_approved`, `conversion_rejected`

**Balance Impact examples:**

| Event | Typical `balance_impact` |
|-------|--------------------------|
| Submitted (paid) | `3.0 VL reserved` |
| Approved / confirmed | `3.0 VL confirmed` |
| Cancelled / rejected | `3.0 VL released` |
| Modified (amount change) | `2.0 VL released; 3.0 VL reserved` |
| Conversion approved | `3.0 VL restored; 3.0 SL deducted` |

---

### Holidays

Company non-working days from `kdtholiday` (KDT / `fldLocID = 1` only). Use for date-picker disabling; weekends stay client-side. Duration is still validated on leave create/update.

| Method | Path | Query |
|--------|------|--------|
| GET | `/holidays` | `from` + `to` (`YYYY-MM-DD`) **or** `year` (2000–2100) |

- If both range and `year` are sent, `from`/`to` win.
- Max range: **400 days**.
- Invalid / missing params → **422**.
- No pagination — response is `{ "data": [ ... ] }`.

Each item:

```json
{
  "id": 166,
  "date": "2026-08-21",
  "name": "Ninoy Aquino Day",
  "holiday_type": 1,
  "is_non_working": true,
  "location_id": 1,
  "location": "KDT"
}
```

| Field | Notes |
|-------|--------|
| `date` | `YYYY-MM-DD` — treat as calendar date (no timezone shift) |
| `holiday_type` | `0` or `1` (raw DB value) |
| `is_non_working` | Always `true` in v1 — disable the day in the picker |

Full detail: `docs/API_HOLIDAYS.md`.

---

### Leaves (employee)

| Method | Path | Body / notes |
|--------|------|----------------|
| GET | `/leaves` | Query: `status`, `page`, `per_page` (1–50, default 15) — **slim** list rows; same `data` / `links` / `meta` envelope as `/activities` |
| POST | `/leaves` | JSON — file VL (**201**) — returns **detail** shape |
| GET | `/leaves/{id}` | Full leave detail (approvals + conversion) |
| PUT or POST | `/leaves/{id}` | JSON — edit pending VL (prefer POST if ever sending files; VL still prohibits files) |
| POST | `/leaves/{id}/cancel` | `{ "cancellation_reason": "..." }` required |
| GET | `/leaves/{id}/attachment` | File download (VL usually has none) |

#### POST `/leaves` body

```json
{
  "emp_id": 42,
  "start_date": "2026-08-17",
  "end_date": "2026-08-20",
  "day_type": "full",
  "leave_reason": "Family trip"
}
```

| Field | Rules |
|-------|--------|
| `emp_id` | Optional. Omit → self. Different from logged-in user → on-behalf (PIC rules) |
| `start_date` / `end_date` | Required dates; end ≥ start |
| `day_type` | `full` \| `half_am` \| `half_pm` |
| `leave_reason` | Required string |
| `leave_attachment` | **Prohibited** on VL |

#### GET `/leaves` — list row (slim)

```json
{
  "id": 1,
  "status": "pending_approval",
  "leave_type": { "id": 1, "code": "VL", "name": "Vacation Leave" },
  "duration": "4.00",
  "day_type": "full",
  "start_date": "2026-08-17",
  "end_date": "2026-08-20",
  "created_at": "2026-07-28T08:00:00+00:00"
}
```

Use `GET /leaves/{id}` when opening a leave for detail / edit / approve / convert.

#### GET `/leaves/{id}` — detail

Who can view: employee on leave, filer, admin, past approver on the leave, **PIC whose formspic `fldGroups` include the employee’s primary group** (same coverage as `GET /approvals`, any status), or an eligible pending approver (e.g. president/admin requirement).

```json
{
  "id": 1,
  "emp_id": 8,
  "filed_by_emp_id": 8,
  "status": "pending_approval",
  "leave_type": { "id": 1, "code": "VL", "name": "Vacation Leave" },
  "duration": "4.00",
  "paid_days": "4.00",
  "lwop_days": "0.00",
  "day_type": "full",
  "start_date": "2026-08-17",
  "end_date": "2026-08-20",
  "leave_reason": "Family trip",
  "has_attachment": false,
  "attachment_url": null,
  "cancellation_reason": null,
  "confirmed_at": null,
  "created_at": "2026-07-28T08:00:00+00:00",
  "employee": {
    "id": 8,
    "employee_number": "8",
    "name": "Alex Employee",
    "group": {
      "id": 3,
      "abbreviation": "SYS",
      "name": "Systems"
    },
    "department": {
      "id": 1,
      "abbreviation": "IT",
      "name": "Information Technology"
    }
  },
  "approval_requirements": [
    {
      "display_order": 1,
      "allowed_levels": [1, 2, 3, 4],
      "required_approver_type": null,
      "is_satisfied": true,
      "satisfied_at": "2026-07-28T09:00:00+00:00",
      "history": {
        "approver_emp_id": 12,
        "approver_name": "Jordan Approver",
        "approver_type": "am",
        "is_approved": true,
        "remarks": null,
        "created_at": "2026-07-28T09:00:00+00:00"
      }
    }
  ],
  "eligible_approvers": [
    {
      "emp_id": 12,
      "name": "Jordan Approver",
      "approver_type": "am",
      "purpose": "Leave approval"
    }
  ],
  "conversion": null
}
```

`employee` is the person on leave (`leave_info.emp_id`) — same nest shape as `GET /approvals` (`group` from `group_list`, `department` from `department_list` via `group_list.dept_id`). `emp_id` remains for convenience.

`eligible_approvers` is who is responsible for the **current stage** (always shown for that stage, even after someone acts). Who actually acted is in `approval_requirements[].history` / conversion review.

| Leave `status` | `eligible_approvers` |
|----------------|----------------------|
| `pending_approval`, `confirmed`, `rejected`, `cancelled` | People matching snapshotted leave requirements (formspic + group + `allowed_levels`, or president/admin when the requirement type says so) |
| `pending_conversion`, `converted_to_sl` | Admin only (`LEAVE_ADMIN_EMP_ID`, purpose `Admin validation`) |

If conversion is rejected, leave returns to `confirmed` and the VL leave-approval list comes back.

When status is `pending_conversion` (or after a conversion exists), `conversion` looks like:

```json
{
  "id": 1,
  "status": "pending_review",
  "from_leave_type": { "id": 1, "code": "VL", "name": "Vacation Leave" },
  "to_leave_type": { "id": 2, "code": "SL", "name": "Sick Leave" },
  "has_med_cert": true,
  "med_cert_url": "http://localhost:8000/api/conversions/1/med-cert",
  "reviewed_by_emp_id": null,
  "remarks": null,
  "reviewed_at": null,
  "created_at": "2026-07-28T10:00:00+00:00"
}
```

Store / update / cancel / approve / reject also return this **detail** shape.

**Edit rules:** only while status is `pending_approval` and no approval history yet.  
**Cancel:** open requests (pending / partially approved); reason required.

---

### Approvals (designated PIC)

| Method | Path | Body / query |
|--------|------|----------------|
| GET | `/approvals` | All Leave Requests (PIC groups). Optional: `?status=confirmed\|rejected\|converted_to_sl`, `?code=VL\|SL`, `?sort=asc\|desc` (default `desc`), `?page=1`, `?per_page=15` (1–50) |
| GET | `/approvals/pending` | — actionable inbox only (slim rows; not paginated) |
| POST | `/leaves/{id}/approve` | empty |
| POST | `/leaves/{id}/reject` | `{ "remarks": "..." }` required |

**Scope**

| Endpoint | Who | Statuses |
|----------|-----|----------|
| `GET /approvals` | Employees whose **primary** group abbreviation is in the viewer’s formspic `fldGroups` (**excludes the viewer**) | Default: `confirmed`, `rejected`, `converted_to_sl`. Optional `?status=` narrows to one of those. |
| `GET /approvals/pending` | Leaves the viewer can **act on** now (PIC group ∩ open requirement) | `pending_approval` only |

If the viewer has no PIC groups, `GET /approvals` returns an empty paginated list. The viewer’s own leaves are not listed (use `GET /leaves`). `pending_approval` and `pending_conversion` are **not** included here (use `/approvals/pending` and `/conversions/pending`). `cancelled` is also omitted. Converted leaves have type **SL** (`leave_type.code`); use `?code=SL` to filter them.

`GET /approvals` uses the same slim row shape and Laravel `data` / `links` / `meta` pagination as `GET /activities`. Sorted by `created_at` (default newest first).

Who can act on pending: formspic PICs whose `fldGroups` include the employee’s **primary** group (`employee_list.group_id`), at allowed levels for that leave. One approval confirms VL.

Slim row shape (`GET /approvals` and `GET /approvals/pending`):

```json
{
  "data": [
    {
      "id": 41,
      "status": "pending_approval",
      "leave_type": { "id": 1, "code": "VL", "name": "Vacation Leave" },
      "duration": "3.00",
      "start_date": "2026-08-17",
      "end_date": "2026-08-19",
      "created_at": "2026-07-28T08:00:00+00:00",
      "employee": {
        "id": 8,
        "employee_number": "8",
        "name": "Alex Employee",
        "group": {
          "id": 3,
          "abbreviation": "SYS",
          "name": "Systems"
        },
        "department": {
          "id": 1,
          "abbreviation": "IT",
          "name": "Information Technology"
        }
      },
      "approvals_completed": 0,
      "approvals_required": 1
    }
  ]
}
```

| Field | Notes |
|-------|--------|
| `employee` | Person on leave (`leave_info.emp_id`) |
| `employee.group` | Primary group (`group_list`) |
| `employee.department` | Parent department (`department_list` via `group_list.dept_id`) |
| `employee_number` | String of `employee_list.id` (same as `employee.id`) |
| `approvals_completed` / `approvals_required` | From snapshotted `leave_approval_requirement` rows |

Open detail with `GET /leaves/{id}` before approve/reject if you need the full leave payload.

---

### VL → SL conversion

| Method | Path | Body |
|--------|------|------|
| POST | `/leaves/{id}/conversion` | **multipart/form-data**: `med_cert` file (**201**) |
| GET | `/conversions/pending` | Admin inbox |
| POST | `/conversions/{id}/approve` | empty |
| POST | `/conversions/{id}/reject` | `{ "remarks": "..." }` required |
| GET | `/conversions/{id}/med-cert` | Download (employee on leave or admin) |

#### Business rules (UI)

- Only **confirmed VL** can be converted (`status` becomes `pending_conversion` while admin reviews).
- Only the **employee on leave** (`emp_id`) may request — not an on-behalf filer.
- Med cert required: pdf / jpg / jpeg / png (default max 5120 KB).
- Re-request allowed after **rejected** (upload a new med cert; leave returns to `confirmed` on reject).
- Approve / reject: **admin only** (`LEAVE_ADMIN_EMP_ID`).
- Filter employee leave list with `GET /leaves?status=pending_conversion`.

#### Multipart example

```js
const form = new FormData();
form.append('med_cert', file);

await fetch(`${API}/leaves/${leaveId}/conversion`, {
  method: 'POST',
  credentials: 'include',
  headers: { Accept: 'application/json' },
  body: form, // do not set Content-Type — browser sets multipart boundary
});
```

#### Conversion resource

```json
{
  "id": 1,
  "leave_id": 10,
  "status": "pending_review",
  "from_leave_type": { "id": 1, "code": "VL", "name": "Vacation Leave" },
  "to_leave_type": { "id": 2, "code": "SL", "name": "Sick Leave" },
  "has_med_cert": true,
  "med_cert_url": "http://localhost:8000/api/conversions/1/med-cert",
  "reviewed_by_emp_id": null,
  "remarks": null,
  "reviewed_at": null,
  "leave": {
    "id": 10,
    "emp_id": 8,
    "status": "pending_conversion",
    "duration": "2.00",
    "paid_days": "2.00",
    "start_date": "2026-07-01",
    "end_date": "2026-07-02",
    "leave_reason": "...",
    "leave_type": { "id": 1, "code": "VL", "name": "Vacation Leave" }
  },
  "created_at": "2026-07-28T10:00:00+00:00"
}
```

After **approve**, leave status becomes `converted_to_sl` and leave type becomes SL. On **reject**, leave returns to `confirmed`.

---

## Suggested UI screens

1. **My leaves** — `GET /leaves` (+ status filter) → open detail with `GET /leaves/{id}`
2. **File VL** — `POST /leaves` (load `GET /holidays` for picker non-working days)
3. **Edit pending VL** — PUT/POST `/leaves/{id}`
4. **Cancel** — reason form → cancel endpoint
5. **Approver inbox** — `GET /approvals/pending` → approve / reject (or open `GET /leaves/{id}` first)
6. **All Leave Requests (approver)** — `GET /approvals` (PIC groups; filter by status / leave type)
7. **Convert VL → SL** — on confirmed VL, upload med cert
8. **Admin conversion inbox** — `GET /conversions/pending` → approve / reject + remarks
9. **Balances** — `GET /balances`
10. **Activity Log** — `GET /activities` (+ leave-type filter)

Build order tip: start with `/me` + balances + holidays + my leaves + file VL, then approvals, then conversion, then activity log.

---

## Emails (backend only — no frontend work)

| Event | To | CC |
|-------|----|----|
| Leave submitted | Designated approvers | — |
| Leave confirmed / rejected | Employee (+ filer if on-behalf) | — |
| Leave cancelled | Designated approvers | — |
| Conversion requested | Admin | Same designated approvers (med cert attached) |
| Conversion approved / rejected | Employee only | — |

Controlled by `LEAVE_NOTIFICATIONS_ENABLED` (default `true`) and `MAIL_*` SMTP.

---

## Not built yet (do not wire UI)

- Direct SL filing (without prior VL conversion)
- Other leave types: EL, ML, PL, PTB, LL
- P4 on-behalf → president filing path
- Attachment upload on VL (intentionally prohibited)

---

## Related docs

| Doc | Topic |
|-----|--------|
| `docs/APPROVAL_RULES.md` | Who can approve / on-behalf / conversion rules |
| `docs/BALANCE_FLOW.md` | Credits, reserve, confirm, VL→SL ledger |
| `docs/API_HOLIDAYS.md` | Holidays list (`kdtholiday`) for date picker |
| `docs/SCHEMA.md` | Table / enum reference |
| `docs/LEGACY_NOTES.md` | Legacy vs revamp differences |
