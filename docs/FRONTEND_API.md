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
| `employment_status` | `regular`, `probationary`, `contractual` (admin balances only; derived from `designation_list`) |

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
  "designation_id": 3,
  "designation": {
    "id": 3,
    "acronym": "SDE",
    "name": "Senior Design Engineer"
  },
  "date_hired": "2020-01-15",
  "role": {
    "approval_level": 2,
    "is_president": false,
    "is_admin": false,
    "pic_groups": [
      { "id": 12, "abbreviation": "SYS", "name": "Systems" },
      { "id": 18, "abbreviation": "EE", "name": "Electrical Engineering" },
      { "id": 21, "abbreviation": "IT", "name": "Information Technology" }
    ]
  }
}
```

`designation` comes from `kdtphdb_new.designation_list` via `employee_list.designation` (nullable if unset / missing).

`group` / `group_id` use `employee_list.group_id`, except employees listed in `LEAVE_EMPLOYEE_GROUP_OVERRIDES` (currently `212:16` — treated as Systems for this app).

`role` comes from `formspic` + config (`LEAVE_PRESIDENT_EMP_ID`, `LEAVE_ADMIN_EMP_ID`):

| Field | Meaning |
|-------|---------|
| `approval_level` | `1`–`4` (P1–P4) from `formspic.fldRole`, or `null` if not a PIC |
| `is_president` | Employee id matches `LEAVE_PRESIDENT_EMP_ID` |
| `is_admin` | Employee id matches `LEAVE_ADMIN_EMP_ID` |
| `pic_groups` | Groups this PIC covers. Abbreviations from `formspic.fldGroups`, hydrated with `id` / `name` from `kdtphdb_new.group_list`. Same object shape as `group`. Order follows `fldGroups`. Empty if not a PIC. Codes with no `group_list` row are omitted. |

---

### Group members (File Leave for Members)

| Method | Path | Notes |
|--------|------|--------|
| GET | `/groups/{group}/members` | Dropdown of employees the viewer may file leave for in that PIC group |

`{group}` is `group_list.id` — the same `id` as `role.pic_groups[].id` on `GET /me`.

The viewer must be a PIC (P1–P4) whose `formspic.fldGroups` includes this group’s abbreviation. Otherwise **403**. Unknown group id → **404**.

The list is the same people `POST /leaves` would accept for on-behalf (`LeaveOnBehalfAuthorizationService`): active employees (`emp_status = 1`) in that group, excluding the viewer, filtered by the on-behalf role matrix. Not paginated. Sorted by `id` (same as `emp_id`).

```json
{
  "data": [
    { "id": 8, "name": "Jane Doe" },
    { "id": 15, "name": "Alex Employee" }
  ]
}
```

| Field | Meaning |
|-------|---------|
| `id` | `employee_list.id` — send as `emp_id` on `POST /leaves` |
| `name` | Full name (`firstname` + `surname`), same value as `full_name` on `GET /me` |

---

### Balances

| Method | Path | Query |
|--------|------|--------|
| GET | `/balances` | optional `?emp_id=212`, optional `?code=VL` (case-insensitive) |

`emp_id` is the same as `POST /leaves`: omit or send the logged-in user → self. A different id uses the on-behalf PIC matrix (`LeaveOnBehalfAuthorizationService`). Not allowed → **403**. Unknown or inactive employee → **422** on `emp_id`. Use `id` from `GET /groups/{group}/members` when filing for a member.

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
| `emp_id` | Optional. Omit → self. Different from logged-in user → on-behalf (PIC rules). Use `id` from `GET /groups/{group}/members`. |
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
  "filed_by": {
    "id": 8,
    "employee_number": "8",
    "name": "Alex Employee"
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

`filed_by` is who submitted the request (`leave_info.filed_by_emp_id`) — slim nest (`id`, `employee_number`, `name`). Same as `employee` when the person files for themselves; a PIC when filed on behalf. `filed_by_emp_id` remains for convenience.

`eligible_approvers` is who is responsible for the **current stage** (always shown for that stage, even after someone acts). Who actually acted is in `approval_requirements[].history` / `conversion.reviewed_by`.

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
  "reviewed_by": null,
  "remarks": null,
  "reviewed_at": null,
  "created_at": "2026-07-28T10:00:00+00:00"
}
```

`reviewed_by` is who approved or rejected the conversion (`leave_conversion.reviewed_by_emp_id`) — slim nest (`id`, `employee_number`, `name`), same as `filed_by`. Null while `status` is `pending_review`. `reviewed_by_emp_id` remains for convenience.

Store / update / cancel / approve / reject also return this **detail** shape.

**Edit rules:** only while status is `pending_approval` and no approval history yet.  
**Cancel:** open requests (pending / partially approved); reason required.

---

### Approvals (designated PIC)

| Method | Path | Body / query |
|--------|------|----------------|
| GET | `/approvals` | All Leave Requests (PIC groups). Optional: `?status=confirmed\|rejected\|pending_conversion\|converted_to_sl`, `?code=VL\|SL`, `?sort=asc\|desc` (default `desc`), `?page=1`, `?per_page=15` (1–50) |
| GET | `/approvals/pending` | — actionable inbox only (slim rows; not paginated) |
| POST | `/leaves/{id}/approve` | empty |
| POST | `/leaves/{id}/reject` | `{ "remarks": "..." }` required |

**Scope**

| Endpoint | Who | Statuses |
|----------|-----|----------|
| `GET /approvals` | Employees whose **primary** group abbreviation is in the viewer’s formspic `fldGroups` (**excludes the viewer**) | Default: `confirmed`, `rejected`, `pending_conversion`, `converted_to_sl`. Optional `?status=` narrows to one of those. |
| `GET /approvals/pending` | Leaves the viewer can **act on** now (PIC group ∩ open requirement) | `pending_approval` only |

If the viewer has no PIC groups, `GET /approvals` returns an empty paginated list. The viewer’s own leaves are not listed (use `GET /leaves`). `pending_approval` is **not** included here (use `/approvals/pending`). Admin conversion action stays on `/conversions/pending`. `cancelled` is omitted. While `pending_conversion`, type is still **VL**; after approval it becomes **SL** (`converted_to_sl`) — use `?code=SL` for converted rows.

`GET /approvals` uses the same slim row shape and Laravel `data` / `links` / `meta` pagination as `GET /activities`. Sorted by `created_at` (default newest first).

Who can act on pending: formspic PICs whose `fldGroups` include the employee’s **primary** group (`employee_list.group_id`, or `LEAVE_EMPLOYEE_GROUP_OVERRIDES`), at allowed levels for that leave. One approval confirms VL.

Slim row shape (`GET /approvals`, `GET /approvals/pending`, and `GET /admin/leaves`):

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

### All Leave Requests (admin)

Org-wide leave list for **`LEAVE_ADMIN_EMP_ID` only**. No PIC / formspic scoping. Includes the admin’s own leaves.

| Method | Path | Query |
|--------|------|--------|
| GET | `/admin/leaves` | Optional: `?status=pending_approval\|pending_conversion\|confirmed\|converted_to_sl`, `?code=VL\|SL`, `?group_id=` (`group_list.id`), `?sort=asc\|desc` (default `desc`), `?page=1`, `?per_page=15` (1–50) |
| GET | `/admin/groups` | No query. Org-wide `group_list` for admin dropdowns (`LEAVE_ADMIN_EMP_ID` only). Includes `dept_id` so the UI can cascade after a department pick. |
| GET | `/admin/departments` | No query. Org-wide `department_list` for admin dropdowns (`LEAVE_ADMIN_EMP_ID` only) |

Non-admin callers get **403**. Same slim row shape and Laravel `data` / `links` / `meta` pagination as `GET /approvals` and `GET /activities`. Sorted by `created_at` (default newest first).

**Scope**

| | |
|--|--|
| Who | Only the employee id in `LEAVE_ADMIN_EMP_ID` |
| Employees | Entire org, including the admin |
| Default statuses | `pending_approval`, `pending_conversion`, `confirmed`, `converted_to_sl` |
| Omitted | `rejected`, `cancelled` |
| `group_id` | Optional. Filters by the person on leave’s **effective** primary group (`employee_list.group_id`, or `LEAVE_EMPLOYEE_GROUP_OVERRIDES`). Omit for all groups. Unknown id → **422**. Dropdown: `GET /admin/groups`. |

Optional `?status=` narrows to one of the allowed values (`rejected` / `cancelled` return **422**). While `pending_conversion`, type is still **VL**; after approval it becomes **SL** (`converted_to_sl`) — use `?code=SL` for converted rows.

Open detail with `GET /leaves/{id}` (admin can view any leave). Conversion review stays on `GET /conversions/pending`.

### Employee balances (admin)

Org-wide leave-credit table for **`LEAVE_ADMIN_EMP_ID` only**. Active employees only (`employee_list.emp_status = 1`). Includes the admin.

| Method | Path | Query / body |
|--------|------|--------|
| GET | `/admin/balances` | Optional: `?q=` (name or employee number), `?department_id=` (`department_list.id`), `?group_id=` (`group_list.id`), `?employment_status=regular\|probationary\|contractual`, `?sort=asc\|desc` (default `asc`, by employee number), `?page=1`, `?per_page=15` (1–50) |
| POST | `/admin/balances` | `{ "emp_id": 8, "code": "VL", "available_delta": "-3.00", "remarks": "Opening balance overstated" }` — signed **available** correction only (`reserved` unchanged) |

Non-admin callers get **403**. Same Laravel `data` / `links` / `meta` pagination as `GET /admin/leaves`. Sorted by employee number (`employee_list.id` / `employee.employee_number`); default **lowest first** (`sort=asc`).

**Scope**

| | |
|--|--|
| Who | Only the employee id in `LEAVE_ADMIN_EMP_ID` |
| Employees | Entire org, **active only**, including the admin |
| `department_id` | Optional. Filters by the employee’s **effective** primary group’s department (`group_list.dept_id`). Unknown id → **422**. Dropdown: `GET /admin/departments`. |
| `group_id` | Optional. Filters by the employee’s **effective** primary group (`employee_list.group_id`, or `LEAVE_EMPLOYEE_GROUP_OVERRIDES`). Unknown id → **422**. Dropdown: `GET /admin/groups` (use `dept_id` to cascade). If both `department_id` and `group_id` are sent, the group must belong to that department → **422** otherwise. |
| `employment_status` | Optional. Derived from `employee_list.designation` (`designation_list`), **not** `emp_status`. Omit for all three. Invalid value → **422**. |
| `q` | Optional. Search bar. Case-insensitive **contains** match on full name (`firstname` + space + `surname`) or employee number (`employee_list.id`, same as `employee.employee_number`). Combines with the other filters (AND). Omit or whitespace-only → no search. Max 100 characters. |
| `sort` | Optional. `asc` (default) or `desc`. Orders by employee number (`employee_list.id`). Numeric, not string order. Invalid value → **422**. |

`employment_status` mapping (`config/leave.php` → `employment`):

| Value | `designation_list.id` |
|-------|------------------------|
| `probationary` | 8, 20, 54 |
| `contractual` | 9, 49, 51, 53 |
| `regular` | any other designation |

Each item is **one employee** with every `leave_type` (stable column set). Missing `leave_balance` rows are `"0.00"`. **ML** is applicable only for female employees (`employee_list.gender = 1`); **PL** only for male (`gender = 0`). When `applicable` is `false`, show “—” (do not treat `"0.00"` as a real balance of that type).

```json
{
  "data": [
    {
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
      "employment_status": "regular",
      "balances": [
        {
          "leave_type_id": 1,
          "code": "VL",
          "name": "Vacation Leave",
          "available": "12.00",
          "reserved": "1.00",
          "applicable": true
        },
        {
          "leave_type_id": 4,
          "code": "ML",
          "name": "Maternity Leave",
          "available": "0.00",
          "reserved": "0.00",
          "applicable": false
        }
      ]
    }
  ]
}
```

`GET /admin/groups` row now includes `dept_id` (`group_list.dept_id`) for the department → group cascade.

**POST** — HR correction of **available** credits. Writes a `manual_adjustment` ledger row. No activity-log row (there is no leave). Non-admin callers get **403**. Success is **200** with **one** employee row in the same shape as a GET item (`{ "data": { "employee", "employment_status", "balances" } }`) so the table can refresh that line.

```json
{
  "emp_id": 8,
  "code": "VL",
  "available_delta": "-3.00",
  "remarks": "Opening balance overstated"
}
```

| Field | Notes |
|-------|--------|
| `emp_id` | Required. Active employee (`employee_list`). Unknown or inactive → **422**. |
| `code` | Required. Must exist on `leave_type` (case-insensitive; stored uppercase). Unknown → **422**. |
| `available_delta` | Required. Signed number, not zero, at most 2 decimal places. `+3` adds, `-3` subtracts **available** only. Would make available negative → **422** (`available_delta`: “Balance cannot go negative.”). |
| `remarks` | Required. Same as other admin remarks (`string`, max 2000). |

**ML** for a male employee or **PL** for a female employee → **422** on `code` (same `applicable` rule as GET). After 15 available / 3 reserved, `-2` VL → available `13.00`, reserved `3.00`.

### Annual entitlement (admin)

Yearly VL/SL grant for **`LEAVE_ADMIN_EMP_ID` only**. Select eligible regulars, review before/after on the client, then confirm. Amounts are always computed on the server (do not send grants in POST).

Tenure is complete years from `employee_list.date_hired` to **1 January** of `year` (Asia/Manila), even if the office opens on the first Monday.

| Tenure as of 1 Jan | VL | SL |
|--------------------|----|----|
| Less than 3 years | +15 | +15 |
| 3 years or more | +15 | +18 |

| Method | Path | Query / body |
|--------|------|--------|
| GET | `/admin/annual-entitlements` | Optional: `?year=2026` (default: current Manila year), `?q=` (name or employee number), `?group_id=` (`group_list.id`), `?emp_ids=8,12` (or `emp_ids[]=8&emp_ids[]=12` — selected employees for the review page), `?sort=asc\|desc` (default `asc`, by employee number), `?page=1`, `?per_page=15` (1–500; send `500` for client-side pagination of the full regular list) |
| POST | `/admin/annual-entitlements` | `{ "year": 2026, "emp_ids": [8, 12] }` — checked employees only. Confirm button. |

Non-admin callers get **403**. GET uses the same Laravel `data` / `links` / `meta` pagination as `GET /admin/balances`. Extra `meta` keys: `year`, `as_of`, `eligible_count`, `granted_count`, `pending_count` (unpaginated totals for the current filters).

**Who appears (GET)**

| | |
|--|--|
| Who | Only the employee id in `LEAVE_ADMIN_EMP_ID` |
| Employees | Active **regulars** only (same designation mapping as admin balances). Must have `date_hired` on or before `as_of`. |
| List | **All** matching regulars, including those already granted this year (`already_granted: true`). Disable those checkboxes. |
| `year` | Optional. Invalid → **422**. |
| `group_id` | Optional. Effective primary group. Unknown id → **422**. Dropdown: `GET /admin/groups`. |
| `q` | Optional. Same name / employee-number search as `GET /admin/balances`. |
| `emp_ids` | Optional. Refetch the checked employees for the review page (fresh available / after). Comma-separated `?emp_ids=8,12` or repeated `emp_ids[]`. Max 500. Still limited to eligible regulars; unknown or ineligible ids are omitted (not 422). Combines with other filters (AND). Invalid → **422**. |
| `sort` | Optional. `asc` (default) or `desc` by employee number. |

Missing `leave_balance` → available `"0.00"`. `vl_after` / `sl_after` are `available + grant` while pending; for `already_granted` they equal current available (do not add a second time). Review table: before = `vl_available` / `sl_available`, to add = `vl_grant` / `sl_grant`, after = `vl_after` / `sl_after`. Reserved is unchanged.

```json
{
  "data": [
    {
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
      "date_hired": "2020-01-15",
      "years_of_service": 5,
      "vl_available": "12.00",
      "sl_available": "8.00",
      "vl_grant": "15.00",
      "sl_grant": "18.00",
      "vl_after": "27.00",
      "sl_after": "26.00",
      "already_granted": false
    }
  ],
  "meta": {
    "current_page": 1,
    "year": 2026,
    "as_of": "2026-01-01",
    "eligible_count": 200,
    "granted_count": 180,
    "pending_count": 20
  }
}
```

Example: hired `2023-01-02`, `year=2026` → as of `2026-01-01` that is **2 years** → SL **15**, even if Confirm is clicked in March.

**POST** — writes `annual_entitlement` ledger rows (VL and SL). No activity-log row. All-or-nothing for the `emp_ids` list. Server recomputes grants from tenure; client amounts are ignored (not accepted).

```json
{
  "year": 2026,
  "emp_ids": [8, 12]
}
```

| Field | Notes |
|-------|--------|
| `year` | Required. Same year as the GET they reviewed. |
| `emp_ids` | Required. Unique ids, min 1, max 500. Only the checked people. Unknown / inactive / not regular / no hire date / hired after 1 Jan → **422**. |

If **any** selected employee already has both VL and SL `annual_entitlement` rows in that Manila calendar year → **422** (nothing is written). Partial (only one type) is completed, not duplicated. Success **200**:

```json
{
  "data": [ { "...same row shape, already_granted true..." } ],
  "year": 2026,
  "as_of": "2026-01-01",
  "granted_count": 2
}
```

### Newly regularized (admin)

One-time mid-year grant for employees who missed the Jan 1 annual VL/SL distribution. **`LEAVE_ADMIN_EMP_ID` only.** Select eligible people, review grants on the client, then confirm. Amounts are always computed on the server (do not send grants in POST).

**As-of** is **today** (Asia/Manila). Regularization date is derived as `date_hired + 6 months` (no DB column). Designation is **ignored** for regular vs probationary (leaders may lag); **contractual** designations are excluded (admin adjusts those manually).

| Method | Path | Query / body |
|--------|------|--------|
| GET | `/admin/newly-regularized` | Optional: `?year=2026` (default: current Manila year), `?q=` (name or employee number), `?group_id=` (`group_list.id`), `?emp_ids=8,12` (or `emp_ids[]=` — selected employees for the review page), `?sort=asc\|desc` (default `asc`, by employee number), `?page=1`, `?per_page=15` (1–500; send `500` for client-side pagination) |
| POST | `/admin/newly-regularized` | `{ "year": 2026, "emp_ids": [8, 12] }` — checked employees only. Confirm button. |

Non-admin callers get **403**. GET uses the same Laravel `data` / `links` / `meta` pagination as annual entitlements. Extra `meta` keys: `year`, `as_of`, `eligible_count`, `granted_count`, `pending_count`.

**Who appears (GET)**

| | |
|--|--|
| Who | Only the employee id in `LEAVE_ADMIN_EMP_ID` |
| Employees | Active, **not contractual**, `date_hired` present, hired **after** 1 January of `year`, tenure **≥ 6 months and &lt; 12 months** as of today, and **no** `annual_entitlement` ledger row in that Manila calendar year |
| List | **All** matching employees, including those already granted this year (`already_granted: true`). Disable those checkboxes. |
| `year` | Optional. Used for Dec proration end and ledger idempotency. Invalid → **422**. |
| `group_id` / `q` / `emp_ids` / `sort` | Same rules as `GET /admin/annual-entitlements`. |

**Grants (server-computed)**

| Type | Amount |
|------|--------|
| VL / SL | Inclusive calendar months from regularization month through December of `year` × **1.25** (e.g. July–Dec = 6 → **7.50**) |
| PTB | Flat **180.00** (one-time) |
| EL | Flat **5.00** (one-time) |

```json
{
  "data": [
    {
      "employee": {
        "id": 808,
        "employee_number": "808",
        "name": "Alex Employee",
        "group": { "id": 3, "abbreviation": "SYS", "name": "Systems" },
        "department": { "id": 1, "abbreviation": "IT", "name": "Information Technology" }
      },
      "date_hired": "2026-01-10",
      "regularization_date": "2026-07-10",
      "months_of_service": "7 months, 16 days",
      "months_prorated": 6,
      "vl_available": "0.00",
      "sl_available": "0.00",
      "ptb_available": "0.00",
      "el_available": "0.00",
      "vl_grant": "7.50",
      "sl_grant": "7.50",
      "ptb_grant": "180.00",
      "el_grant": "5.00",
      "vl_after": "7.50",
      "sl_after": "7.50",
      "ptb_after": "180.00",
      "el_after": "5.00",
      "already_granted": false
    }
  ],
  "meta": {
    "current_page": 1,
    "year": 2026,
    "as_of": "2026-08-26",
    "eligible_count": 12,
    "granted_count": 3,
    "pending_count": 9
  }
}
```

Select table: `date_hired`, `regularization_date`, `months_of_service`. Review table: `months_prorated`, `vl_grant` / `sl_grant` / `ptb_grant` / `el_grant` (and optional before/after from `*_available` / `*_after`). For `already_granted`, after equals current available.

**POST** — writes `regularization_entitlement` ledger rows (VL, SL, PTB, EL). No activity-log row. All-or-nothing for the `emp_ids` list. Server recomputes grants; client amounts are ignored.

```json
{
  "year": 2026,
  "emp_ids": [808, 899]
}
```

| Field | Notes |
|-------|--------|
| `year` | Required. Same year as the GET they reviewed. |
| `emp_ids` | Required. Unique ids, min 1, max 500. Unknown / ineligible → **422**. |

If **any** selected employee already has all four types (`VL`, `SL`, `PTB`, `EL`) with `regularization_entitlement` in that Manila calendar year → **422** (nothing is written). Partial (some types missing) is completed, not duplicated. Success **200**:

```json
{
  "data": [ { "...same row shape, already_granted true..." } ],
  "year": 2026,
  "as_of": "2026-08-26",
  "granted_count": 2
}
```

### Approval reminders (admin)

Nudge waiting PICs / president on `pending_approval` leaves. **`LEAVE_ADMIN_EMP_ID` only** (403 otherwise). Does not mail the admin even if they are also a PIC.

Locks are **independent** and reset on the **Asia/Manila calendar day**. Do not use localStorage.

| Action | Lock |
|--------|------|
| **Bulk** | Once per Manila day (`can_send: false`). Does **not** disable singles. Generic email with that approver’s pending count (no leave list). |
| **Single** | Once per `(leave_id, approver_emp_id)` per Manila day. Does **not** disable bulk. Other singles stay available. Detailed leave email. |

| Method | Path | Body |
|--------|------|------|
| GET | `/admin/reminders` | — banner stats, no send |
| POST | `/admin/reminders` | empty — org-wide **generic** reminder (one email per waiting approver) |
| POST | `/leaves/{id}/reminders` | `{ "approver_emp_id": 12 }` — that leave only |

**GET**

```json
{
  "approver_count": 8,
  "request_count": 14,
  "last_sent_at": "2026-08-24T09:12:00+08:00",
  "can_send": true,
  "singles_locked": false,
  "reminded_pairs": [
    { "leave_id": 41, "approver_emp_id": 12 }
  ]
}
```

| Field | Notes |
|-------|--------|
| `approver_count` | Unique waiting approvers with email (excludes admin) |
| `request_count` | Unique `pending_approval` leaves those people can still act on |
| `last_sent_at` | Last successful **bulk** send, Manila offset; `null` if never. All-tab “Last Reminder Sent”. |
| `can_send` | `false` if mail is off or **bulk** already went out today (Manila) |
| `singles_locked` | Always `false`. Bulk does not lock singles; ignore this for disabling buttons. |
| `reminded_pairs` | Singles sent today — disable those buttons only |

**POST bulk / single** (on success)

```json
{
  "approver_count": 8,
  "request_count": 14,
  "email_count": 8,
  "sent_at": "2026-08-24T09:12:00+08:00"
}
```

Single returns `approver_count` / `request_count` / `email_count` of `1`. Bulk `email_count` matches `approver_count` when every send succeeds.

| Status | When |
|--------|------|
| **429** | Bulk already sent today, or that leave+approver already singled today — `{ "message": "...", "last_sent_at": "..." }` |
| **422** | Mail disabled, nobody to remind, leave not `pending_approval`, or `approver_emp_id` is not waiting / is admin |
| **403** | Caller is not admin |

429 messages:

- `"An org-wide reminder was already sent today."` — bulk today (blocks `POST /admin/reminders` only)
- `"This approver was already reminded for this leave today."` — that pair already singled today

Scope is always org-wide for bulk (ignores All-tab `group_id` / `code` filters). Recipients are people who can still act on the open requirement (same set as submit mail, minus admin). No activity-log row.

---

### Team Leave Calendar (approver)

Month/week grid of **who is out** in the viewer’s PIC groups. Bars are not clickable; `id` is a stable row key only.

| Method | Path | Query |
|--------|------|--------|
| GET | `/team/calendar` | `from` + `to` (`YYYY-MM-DD`) required. Optional `?code=VL`, optional `?group_id=` (`pic_groups[].id`) |

**Scope**

- Employees whose **effective primary group** is in the viewer’s `formspic.fldGroups` (**includes the viewer’s own leave**)
- Statuses: `confirmed`, `pending_conversion`, `converted_to_sl` only (`pending_approval`, `rejected`, `cancelled` omitted)
- `pending_conversion` is still **VL**; `converted_to_sl` is **SL** — `?code=` filters the current type
- Optional `group_id` must be one of `GET /me` `role.pic_groups` — otherwise **403**. Omit for all PIC groups. With `group_id`, the viewer’s own leave is included only if their effective group matches that id
- Not a PIC (and no `group_id`): the viewer’s own approved leaves only
- No pagination. Max range: **62 days**. Invalid / missing `from`/`to` → **422**

Leaves overlapping the range with **no workdays** in view (weekends/holidays only) are omitted.

Paint cells from `dates` (workdays only, already clipped to `from`–`to`). Skip painting from `start_date`/`end_date` or you will put bars on weekends and holidays.

```json
{
  "data": [
    {
      "id": 41,
      "status": "confirmed",
      "duration": "5.00",
      "day_type": "full",
      "start_date": "2026-07-15",
      "end_date": "2026-07-21",
      "dates": ["2026-07-15", "2026-07-17", "2026-07-20", "2026-07-21"],
      "leave_type": { "id": 1, "code": "VL", "name": "Vacation Leave" },
      "employee": {
        "id": 15,
        "employee_number": "15",
        "name": "Emman De Leon",
        "group": { "id": 16, "abbreviation": "SYS", "name": "Systems" }
      }
    }
  ]
}
```

| Field | Notes |
|-------|--------|
| `dates` | Workdays the person is out, clipped to `from`–`to`. Same weekend/holiday rules as leave duration. |
| `duration` | Full leave length (not clipped to `from`–`to`). Half-day is `"0.50"` with one date in `dates`. |
| `day_type` | `full`, `half_am`, or `half_pm` |
| `employee.employee_number` | String of `employee_list.id` (same as `employee.id`) |
| `employee.group` | Effective primary group (`LEAVE_EMPLOYEE_GROUP_OVERRIDES` applied) |
| `id` | `leave_info.id` — list key only (calendar bars are not clickable) |

Filter dropdowns: leave types from `leave_type.code`; groups from `GET /me` `role.pic_groups`.

---

### VL → SL conversion

| Method | Path | Body |
|--------|------|------|
| POST | `/leaves/{id}/conversion` | **multipart/form-data**: `med_cert` file (**201**) |
| GET | `/conversions/pending` | Admin inbox |
| POST | `/conversions/{id}/approve` | empty |
| POST | `/conversions/{id}/reject` | `{ "remarks": "..." }` required |
| GET | `/conversions/{id}/med-cert` | Download (employee on leave, PIC-group approvers, or admin) |

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
  "reviewed_by": null,
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
    "leave_type": { "id": 1, "code": "VL", "name": "Vacation Leave" },
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
    }
  },
  "created_at": "2026-07-28T10:00:00+00:00"
}
```

`leave.employee` is the person on leave (`leave_info.emp_id`) — same nest as `GET /leaves/{id}` and `GET /approvals`. `emp_id` remains for convenience.

After **approve** or **reject**, `reviewed_by` is the admin (slim nest, same as `filed_by`). It is `null` while `pending_review`.

After **approve**, leave status becomes `converted_to_sl` and leave type becomes SL. On **reject**, leave returns to `confirmed`.

---

## Suggested UI screens

1. **My leaves** — `GET /leaves` (+ status filter) → open detail with `GET /leaves/{id}`
2. **File VL** — `POST /leaves` (load `GET /holidays` for picker non-working days). On-behalf: `GET /me` `pic_groups` → `GET /groups/{id}/members` → `GET /balances?emp_id=` then `emp_id` on submit
3. **Edit pending VL** — PUT/POST `/leaves/{id}`
4. **Cancel** — reason form → cancel endpoint
5. **Approver inbox** — `GET /approvals/pending` → approve / reject (or open `GET /leaves/{id}` first)
6. **All Leave Requests (approver)** — `GET /approvals` (PIC groups; filter by status / leave type)
7. **All Leave Requests (admin)** — `GET /admin/leaves` (`LEAVE_ADMIN_EMP_ID` only; org-wide; filter by status / leave type / group). Banner: `GET /admin/reminders` → `POST /admin/reminders`. Detail: `POST /leaves/{id}/reminders`
8. **Team Leave Calendar** — `GET /team/calendar?from=&to=` (optional `code`, `group_id` from `pic_groups`)
9. **Convert VL → SL** — on confirmed VL, upload med cert
10. **Admin conversion inbox** — `GET /conversions/pending` → approve / reject + remarks
11. **Balances** — `GET /balances` (optional `?emp_id=` for on-behalf)
12. **Employee balances (admin)** — `GET /admin/balances` (`LEAVE_ADMIN_EMP_ID` only; org-wide; `?q=` name or employee number; `?sort=asc|desc` by employee number; filter by department / group / employment status). Dropdowns: `GET /admin/departments`, `GET /admin/groups` (`dept_id` for cascade). Adjust available: `POST /admin/balances` (`emp_id`, `code`, `available_delta`, `remarks`)
13. **Annual entitlement (admin)** — `GET /admin/annual-entitlements` (regulars; `already_granted`; tenure as of 1 Jan). Review: refetch `GET /admin/annual-entitlements?year=&emp_ids=` then Confirm `POST /admin/annual-entitlements` `{ year, emp_ids }`
14. **Newly regularized (admin)** — `GET /admin/newly-regularized` (6–12 months tenure; hired after 1 Jan; exclude contractual / annual recipients). Review: refetch `?year=&emp_ids=` then Confirm `POST /admin/newly-regularized` `{ year, emp_ids }`
15. **Activity Log** — `GET /activities` (+ leave-type filter)

Build order tip: start with `/me` + balances + holidays + my leaves + file VL, then approvals, then conversion, then activity log.

---

## Emails

Submit / confirm / reject / cancel / conversion mail is sent by the API with no extra frontend call.

Admin reminders are triggered from the UI (`GET`/`POST /admin/reminders`, `POST /leaves/{id}/reminders`).

| Event | To | CC |
|-------|----|----|
| Leave submitted | Designated approvers | — |
| Admin reminder (bulk) | Each waiting PIC / president (generic: N pending requests need approval) | — |
| Admin reminder (single) | That waiting approver | — |
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
