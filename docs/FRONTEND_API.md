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
| Leave `status` | `pending_approval`, `confirmed`, `pending_conversion`, `rejected`, `cancelled`, `converted_to_sl`, `converted_to_el` |
| Conversion `status` | `pending_review`, `approved`, `rejected` |
| `activity_type` | `submitted`, `modified`, `cancelled`, `rejected`, `attachment_added`, `leave_confirmed`, `conversion_requested`, `conversion_approved`, `conversion_rejected`, `annual_credits_distributed`, `regularization_credits_distributed`, `contractual_credits_distributed`, `credits_adjusted` |
| `employment_status` | `regular`, `probationary`, `contractual` (admin balances only; derived from `emp_prof.fldDesig` → `designation_list`) |

**VL** and **ML** do **not** accept `leave_attachment`. **SL** and **PTB** require **1–5** `leave_attachment` files (medical certificates) on submit. **EL** and **LL** require **1–5** `leave_attachment` files (supporting documents). **PL** requires **1–5** `leave_attachment` files (Paternity Notification Form). Conversion to **SL** uses field name **`med_cert[]`**. Conversion to **EL** uses **`leave_attachment[]`**. Each file: pdf / jpg / jpeg / png, max **10 MB**.

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
  "gender": 1,
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

`gender` is `employee_list.gender`: `0` male, `1` female (nullable). Use this to show **ML** (female) vs **PL** (male) when filing for yourself. Any other value: hide both; submit still **422**.

`designation` comes from `kdtphdb.emp_prof.fldDesig` (acronym) matched to `kdtphdb_new.designation_list`. `KDTP` always resolves to id **29** (KDT President). If `fldDesig` is missing or unknown, falls back to `employee_list.designation`. Nullable if unset / missing.

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

**VL advance notice:** File Leave for Members **does not** apply the 3-workday / 1-calendar-month start-date gate (self-file does). Do not show the “Filing not allowed” banner on this screen for that rule.

```json
{
  "data": [
    { "id": 8, "name": "Jane Doe", "gender": 1 },
    { "id": 15, "name": "Alex Employee", "gender": 0 }
  ]
}
```

| Field | Meaning |
|-------|---------|
| `id` | `employee_list.id` — send as `emp_id` on `POST /leaves` |
| `name` | Full name (`firstname` + `surname`), same value as `full_name` on `GET /me` |
| `gender` | `employee_list.gender`: `0` male, `1` female (nullable). Use the **selected member** (not the PIC) to show ML vs PL on-behalf. |

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
| `remarks` | Present on reject / cancel / conversion request (employee reason) / conversion reject; otherwise usually `null` |

**`activity_type` values (this endpoint):** `submitted`, `modified`, `cancelled`, `rejected`, `attachment_added`, `leave_confirmed`, `conversion_requested`, `conversion_approved`, `conversion_rejected`

Credit distributions and manual adjustments are **not** returned here — see **Administrative Log (admin)**.

**Balance Impact examples:**

| Event | Typical `balance_impact` |
|-------|--------------------------|
| Submitted (paid) | `3.0 VL reserved` |
| Approved / confirmed | `3.0 VL confirmed` |
| Cancelled (pending) / rejected | `3.0 VL released` |
| Cancelled (admin, confirmed) | `3.0 VL restored` |
| Modified (amount change) | `2.0 VL released; 3.0 VL reserved` |
| Conversion approved | `3.0 VL restored; 3.0 SL deducted` |

---

### Administrative Log (admin)

Org-wide activity feed for **`LEAVE_ADMIN_EMP_ID` only**. Includes leave-lifecycle events (submit, edit, cancel, reject, confirm, conversion) for the whole org, plus credit grants.

| Method | Path | Query |
|--------|------|--------|
| GET | `/admin/activities` | Optional: `?q=` (employee name or number), `?group_id=` (`group_list.id`), `?code=VL\|SL`, `?month=2026-06` (`YYYY-MM`, Asia/Manila), `?sort=asc\|desc` (default `desc`), `?page=1`, `?per_page=15` (1–50) |

Non-admin callers get **403**. Same Laravel `data` / `links` / `meta` pagination as `GET /activities`. Sorted by timestamp (`created_at`); default **newest first**. Dropdown for groups: `GET /admin/groups`.

**Scope**

| | |
|--|--|
| Who | Only the employee id in `LEAVE_ADMIN_EMP_ID` |
| Rows | Entire org, including the admin’s own leaves |
| `q` / `group_id` | Person on leave, the adjusted employee (manual), or **any recipient** of a batch distribution |
| `code` | Leave type on the row. Conversion rows also match **from** or **to** (so `?code=VL` still finds conversion activity after the leave becomes SL or EL) |
| `month` | Calendar month of `timestamp` in **Asia/Manila**. Unknown format → **422** |

Does **not** include `balance_impact` / `ledger`. Open leave detail with `GET /leaves/{id}` when `leave_id` is set.

**Leave row** (same fields as below; `leave_id` set):

```json
{
  "id": 12,
  "leave_id": 41,
  "timestamp": "2026-06-03T14:20:00+00:00",
  "activity_type": "leave_confirmed",
  "activity": "Leave request approved",
  "leave_type": { "id": 1, "code": "VL", "name": "Vacation Leave" },
  "employee": {
    "id": 807,
    "employee_number": "807",
    "name": "Sam Rivera",
    "group": { "id": 3, "abbreviation": "SYS", "name": "Systems" }
  },
  "performed_by": { "id": 12, "name": "Ariane Derigay" },
  "remarks": null
}
```

**Batch credit distribution** — one row **per leave type** granted in that Confirm (not per employee). Annual → typically VL + SL. Newly regularized → VL, SL, PTB (only types actually granted). Contractual → VL and/or SL. Render `employee.employee_number` as **—** when null.

```json
{
  "id": 88,
  "leave_id": null,
  "timestamp": "2026-01-01T01:00:00+00:00",
  "activity_type": "annual_credits_distributed",
  "activity": "Annual leave credits distributed",
  "leave_type": { "id": 2, "code": "SL", "name": "Sick Leave" },
  "employee": {
    "id": null,
    "employee_number": null,
    "name": "Multiple Employees",
    "group": null
  },
  "performed_by": { "id": 12, "name": "Ariane Derigay" },
  "remarks": "Annual entitlement 2026"
}
```

**Manual adjustment** — one solo row per `POST /admin/balances`:

```json
{
  "id": 89,
  "leave_id": null,
  "timestamp": "2026-06-03T08:15:00+00:00",
  "activity_type": "credits_adjusted",
  "activity": "Leave credits adjusted",
  "leave_type": { "id": 1, "code": "VL", "name": "Vacation Leave" },
  "employee": {
    "id": 807,
    "employee_number": "807",
    "name": "Sam Rivera",
    "group": { "id": 3, "abbreviation": "SYS", "name": "Systems" }
  },
  "performed_by": { "id": 12, "name": "Ariane Derigay" },
  "remarks": "Opening balance overstated"
}
```

| Field | Notes |
|-------|--------|
| `activity` | UI label (see `activity_type` below) |
| `leave_type.name` | For conversion rows: **"Vacation Leave to Sick Leave"** (`id` / `code` stay the **from** type, VL) |
| `employee.name` | Person on leave, the adjusted employee, or **"Multiple Employees"** for batch grants |
| `performed_by.name` | Actor (employee, filer, approver, or admin) |
| `remarks` | Cancel / reject / conversion request (employee reason) / conversion reject / adjustment reason; batch grants use the ledger remarks string |
| `leave_id` | `null` on credit rows |

**`activity_type` values**

| Value | `activity` |
|-------|------------|
| `submitted` | Leave request submitted |
| `modified` | Leave request modified |
| `cancelled` | Leave request cancelled |
| `rejected` | Leave request rejected |
| `attachment_added` | Attachment added |
| `leave_confirmed` | Leave request approved |
| `conversion_requested` | VL to SL conversion requested (or **VL to EL** when `to_leave_type.code` is `EL`) |
| `conversion_approved` | VL to SL conversion approved (or **VL to EL**) |
| `conversion_rejected` | VL to SL conversion rejected (or **VL to EL**) |
| `annual_credits_distributed` | Annual leave credits distributed |
| `regularization_credits_distributed` | Newly regularized credits distributed |
| `contractual_credits_distributed` | Contractual leave credits distributed |
| `credits_adjusted` | Leave credits adjusted |

Credit rows are written at Confirm / adjust time going forward. Existing ledger grants from before this endpoint are **not** backfilled.

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
| POST | `/leaves` | File VL/ML (JSON) or SL/PTB/EL/LL/PL (multipart + required `leave_attachment[]`, 1–5 files) — **201**, returns **detail** shape |
| GET | `/leaves/{id}` | Full leave detail (approvals + conversion) |
| PUT or POST | `/leaves/{id}` | Edit pending leave dates/reason (JSON). Files are **not** accepted here |
| POST | `/leaves/{id}/attachments` | Add 1+ files to pending SL/PTB/EL/LL/PL (multipart `leave_attachment[]`). Total after add ≤ 5 |
| GET | `/leaves/{id}/attachments/{attachmentId}` | Download one file (required for SL/PTB/EL/LL/PL; VL/ML have none) |
| DELETE | `/leaves/{id}/attachments/{attachmentId}` | Remove one file from pending SL/PTB/EL/LL/PL. Cannot drop below 1 |
| POST | `/leaves/{id}/cancel` | `{ "cancellation_reason": "..." }` required. Employee only; `pending_approval` only |

#### POST `/leaves` body

**Vacation Leave** — JSON (or form fields). Omit `code` or send `"VL"`. No file.

Self-filed VL of **3.00 or more workdays** must start at least **1 calendar month** from today (Asia/Manila). Example: filed **1 Sep 2026** → earliest 3-day start is **1 Oct 2026**; **7–9 Sep** → **422** on `start_date`. Shorter VL (1–2 workdays or half-day) is not gated. **File Leave for Members** (on-behalf `emp_id`) skips this — any PIC already allowed to file for that member. The same gate runs on edit.

```json
{
  "emp_id": 42,
  "code": "VL",
  "start_date": "2026-08-17",
  "end_date": "2026-08-20",
  "day_type": "full",
  "leave_reason": "Family trip"
}
```

**Sick Leave** — `multipart/form-data`. `code` must be `SL`. `leave_attachment[]` (medical certificates) is **required** (1–5 files). Duration is **workdays** (weekends/holidays skipped). Extra days beyond SL credits become LWOP.

```
emp_id            42                  (optional)
code              SL
start_date        2026-08-17
end_date          2026-08-20
day_type          full
leave_reason      Flu
leave_attachment[]  (file: pdf, jpg, jpeg, png; max 10 MB each; 1–5 files)
```

**PTB Leave** — `multipart/form-data`. `code` must be `PTB`. `leave_attachment[]` required (1–5 files). `day_type` must be `full`. Duration is **inclusive calendar days** (weekends and holidays count). No LWOP: duration **> remaining PTB credits** → **422**. Multiple PTB filings are allowed until credits are gone. Zero credits → cannot file (admin can grant via newly-regularized 180 or `POST /admin/balances`).

```
emp_id            42
code              PTB
start_date        2026-08-01
end_date          2026-08-30
day_type          full
leave_reason      PTB treatment
leave_attachment[]  (file)
```

**Emergency Leave** — `multipart/form-data`. `code` must be `EL`. `leave_attachment[]` (supporting documents) is **required** (1–5 files). `day_type` must be `full`. Duration is **workdays** (weekends/holidays skipped). No LWOP: duration **> remaining EL credits** → **422**. Multiple EL filings are allowed until credits are gone. Zero credits → cannot file (admin can grant via `POST /admin/balances`; EL is given at hiring, not on the newly-regularized screen). Typical remaining cap is **5.00**.

```
emp_id            42
code              EL
start_date        2026-08-17
end_date          2026-08-18
day_type          full
leave_reason      Family emergency
leave_attachment[]  (file)
```

Example: 2 workdays next week + 3 workdays the week after = two separate EL requests. If remaining is 3, a 4-workday EL is **422**; file 3 EL then a separate VL for the rest (VL may be LWOP).

**Long Leave** — `multipart/form-data`. `code` must be `LL`. **`leave_attachment[]`** (supporting documents) is **required** (1–5 files). `day_type` must be `full`. Always unpaid (`paid_days=0`, `lwop_days=duration`); no credits are reserved. Duration is **inclusive calendar days**, bounded by **calendar months**: end date must be ≥ start + 1 month − 1 day and ≤ start + 3 months − 1 day. Dates cannot overlap other active leave. Another pending LL also blocks a new file. Confirmed LLs do not impose a cooldown.

```
emp_id            42                  (optional)
code              LL
start_date        2026-09-15
end_date          2026-12-14
day_type          full
leave_reason      Personal long leave
leave_attachment[]  (file: pdf, jpg, jpeg, png; max 10 MB each; 1–5 files)
```

Example: start **15 Sep 2026** → earliest end **14 Oct 2026**, latest end **14 Dec 2026**.

**Maternity Leave** — JSON. `code` must be `ML`. **No** attachment. Female employees only (`employee_list.gender = 1`; male → **422**). Hide ML unless the person on leave has `gender: 1` from `GET /me` (self) or `GET /groups/{group}/members` (on-behalf). `day_type` must be `full`. **Exactly 105 inclusive calendar days** (weekends/holidays count). FE: pick **start**; set `end_date` to the 105th day (`start + 104 days`). Credits are **not** pre-loaded. On submit the API grants 105 ML then reserves them (paid ML, not LWOP). Reject/cancel reverses the grant. Unlimited confirmed MLs. Another pending ML blocks a new file.

```json
{
  "emp_id": 42,
  "code": "ML",
  "start_date": "2026-09-01",
  "end_date": "2026-12-14",
  "day_type": "full",
  "leave_reason": "Maternity leave"
}
```

Example: start **1 Sep 2026** → end **14 Dec 2026**.

**Paternity Leave** — `multipart/form-data`. `code` must be `PL`. **`leave_attachment[]`** (Paternity Notification Form) is **required** (1–5 files). Male employees only (`gender = 0`; female → **422**). Hide PL unless the person on leave has `gender: 0` from `GET /me` (self) or `GET /groups/{group}/members` (on-behalf). `day_type` must be `full`. **Exactly 7 workdays** in one request (cannot file 1–2 days). Start must be a workday. FE: pick **start**; set `end_date` to the 7th workday (`GET /holidays` for non-working days). On submit the API grants 7 PL then reserves them. Reject/cancel reverses the grant. At most **4 confirmed** PL lifetime (rejected/cancelled do not count). Another pending PL blocks a new file.

```
emp_id            42                  (optional)
code              PL
start_date        2026-08-17
end_date          2026-08-25
day_type          full
leave_reason      Paternity leave
leave_attachment[]  (file: pdf, jpg, jpeg, png; max 10 MB each; 1–5 files)
```

Example: start Monday **17 Aug 2026** with no holidays that week → end **25 Aug 2026** (7 workdays).

| Field | Rules |
|-------|--------|
| `emp_id` | Optional. Omit → self. Different from logged-in user → on-behalf (PIC rules). Use `id` from `GET /groups/{group}/members`. |
| `code` | Optional. `VL` (default), `SL`, `PTB`, `EL`, `LL`, `ML`, or `PL` (case-insensitive). Other types → **422**. |
| `start_date` / `end_date` | Required dates; end ≥ start. Overlap with another open leave for the same employee → **422** (`start_date`: "You already have leave filed for these dates.") Self-filed VL of 3+ workdays with start sooner than 1 calendar month → **422** on `start_date` (on-behalf skips this). |
| `day_type` | `full` \| `half_am` \| `half_pm`. **PTB, EL, LL, ML, and PL: `full` only** |
| `leave_reason` | Required string |
| `leave_attachment` | **Prohibited** on VL and ML. **Required** on SL and PTB (med certs), EL and LL (supporting documents), and PL (Paternity Notification Form): array of 1–5 files, **10 MB** each. Repeat the field as `leave_attachment[]`. |

```js
const form = new FormData();
form.append('code', 'SL');
form.append('start_date', '2026-08-17');
form.append('end_date', '2026-08-20');
form.append('day_type', 'full');
form.append('leave_reason', 'Flu');
form.append('leave_attachment[]', file1);
form.append('leave_attachment[]', file2); // optional 2nd–5th files
```

Overlap is by date range, not leave type. A pending or confirmed leave for those dates blocks a new request of any type (for VL→SL: convert the confirmed VL, or cancel a still-pending VL and refile). Rejected / cancelled leaves do not block.

**Approval:** VL uses the PIC matrix (one approval confirms). SL, PTB, EL, LL, ML, and PL snapshot **admin only** (`required_approver_type = admin`). PICs are CC'd on the submit email; they do not approve. Admin acts via `POST /leaves/{id}/approve` or `reject` (same as VL). Pending admin-approved types appear in `GET /approvals/pending` for **admin** and in `GET /admin/leaves`.

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
  "attachments": [],
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
| `pending_conversion`, `converted_to_sl`, `converted_to_el` | Admin only (`LEAVE_ADMIN_EMP_ID`, purpose `Admin validation`) |

If conversion is rejected, leave returns to `confirmed` and the VL leave-approval list comes back.

When status is `pending_conversion` (or after a conversion exists), `conversion` looks like:

```json
{
  "id": 1,
  "status": "pending_review",
  "from_leave_type": { "id": 1, "code": "VL", "name": "Vacation Leave" },
  "to_leave_type": { "id": 2, "code": "SL", "name": "Sick Leave" },
  "has_med_cert": true,
  "med_certs": [
    {
      "id": 1,
      "name": "medcert.pdf",
      "url": "http://localhost:8000/api/conversions/1/attachments/1"
    }
  ],
  "reason": "Acute respiratory infection",
  "reviewed_by_emp_id": null,
  "reviewed_by": null,
  "remarks": null,
  "reviewed_at": null,
  "created_at": "2026-07-28T10:00:00+00:00"
}
```

`reviewed_by` is who approved or rejected the conversion (`leave_conversion.reviewed_by_emp_id`) — slim nest (`id`, `employee_number`, `name`), same as `filed_by`. Null while `status` is `pending_review`. `reviewed_by_emp_id` remains for convenience.

`conversion.reason` is the **employee’s** conversion reason (why VL → SL or VL → EL). `conversion.remarks` is the **admin reject note** only (null while pending / after approve).

Store / update / cancel / approve / reject also return this **detail** shape.

**Edit rules:** only while status is `pending_approval` and no approval history yet. **Self-file:** the employee may edit. **On-behalf:** the employee on leave or the original filer (`filed_by_emp_id`) may edit dates, reason, and attachments. Strangers get **403** (`You are not allowed to modify this leave request.`). Show edit when the viewer is `employee.id` or `filed_by.id`. VL and ML prohibit files. SL, PTB, EL, LL, and PL add files with `POST /leaves/{id}/attachments` and remove with `DELETE /leaves/{id}/attachments/{id}` (at least one file must remain; total ≤ 5). Self-filed VL still cannot be edited to 3+ workdays inside the 1-calendar-month window. PTB and EL stay `day_type=full` and cannot exceed remaining credits of that type. LL stays 1–3 calendar months (always unpaid). ML stays exactly 105 calendar days. PL stays exactly 7 workdays.  
**Cancel (employee):** open requests (`pending_approval`, including after a partial approval); reason required. Only the **employee on leave** may cancel (including on-behalf). Pending VL that should have been SL: cancel, then file SL with the med cert. Confirmed leave cannot be cancelled by the employee (**422**).
**Cancel (admin):** confirmed leave only, via `POST /admin/leaves/{id}/cancel`. See **All Leave Requests (admin)**. `pending_conversion` / converted statuses cannot be cancelled — reject the conversion first if needed.

---

### Approvals (designated PIC)

| Method | Path | Body / query |
|--------|------|----------------|
| GET | `/approvals` | All Leave Requests (PIC groups). Optional: `?status=confirmed\|rejected\|pending_conversion\|converted_to_sl\|converted_to_el`, `?code=VL\|SL\|PTB\|EL\|LL\|ML\|PL`, `?sort=asc\|desc` (default `desc`), `?page=1`, `?per_page=15` (1–50) |
| GET | `/approvals/pending` | — actionable inbox only (slim rows; not paginated) |
| POST | `/leaves/{id}/approve` | empty |
| POST | `/leaves/{id}/reject` | `{ "remarks": "..." }` required |

**Scope**

| Endpoint | Who | Statuses |
|----------|-----|----------|
| `GET /approvals` | Employees whose **primary** group abbreviation is in the viewer’s formspic `fldGroups` (**excludes the viewer**) | Default: `confirmed`, `rejected`, `pending_conversion`, `converted_to_sl`, `converted_to_el`. Optional `?status=` narrows to one of those. |
| `GET /approvals/pending` | Leaves the viewer can **act on** now (PIC group ∩ open requirement, or admin on admin-approved types) | `pending_approval` only |

If the viewer has no PIC groups, `GET /approvals` returns an empty paginated list. The viewer’s own leaves are not listed (use `GET /leaves`). `pending_approval` is **not** included here (use `/approvals/pending`). Admin conversion action stays on `/conversions/pending`. Direct-filed pending SL/PTB/EL/LL/ML/PL is **not** on `/conversions/pending` — admin approves it via `POST /leaves/{id}/approve`. `cancelled` is omitted. While `pending_conversion`, type is still **VL**; after approval it becomes **SL** (`converted_to_sl`) or **EL** (`converted_to_el`) — use `?code=SL` or `?code=EL` for converted rows. Direct-filed SL that is already confirmed is also `code=SL` with status `confirmed`.

`GET /approvals` uses the same slim row shape and Laravel `data` / `links` / `meta` pagination as `GET /activities`. Sorted by `created_at` (default newest first).

Who can act on pending: formspic PICs whose `fldGroups` include the employee’s **primary** group (`employee_list.group_id`, or `LEAVE_EMPLOYEE_GROUP_OVERRIDES`), at allowed levels for that leave. One PIC approval confirms **VL**. **SL**, **PTB**, **EL**, **LL**, **ML**, and **PL** are admin only (`LEAVE_ADMIN_EMP_ID`) — PICs do not approve them.

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
| GET | `/admin/leaves` | Optional: `?status=pending_approval\|pending_conversion\|confirmed\|converted_to_sl\|converted_to_el`, `?code=VL\|SL\|PTB\|EL\|LL\|ML\|PL`, `?group_id=` (`group_list.id`), `?sort=asc\|desc` (default `desc`), `?page=1`, `?per_page=15` (1–50) |
| POST | `/admin/leaves/{id}/cancel` | `{ "cancellation_reason": "..." }` required. **Confirmed** leave only |
| GET | `/admin/groups` | No query. Org-wide `group_list` for admin dropdowns (`LEAVE_ADMIN_EMP_ID` only). Includes `dept_id` so the UI can cascade after a department pick. |
| GET | `/admin/departments` | No query. Org-wide `department_list` for admin dropdowns (`LEAVE_ADMIN_EMP_ID` only) |
| GET | `/admin/employees` | Optional: `?group_id=` (`group_list.id`). Slim org-wide employee picker (`LEAVE_ADMIN_EMP_ID` only). Do **not** use `GET /admin/balances` for this. |

Non-admin callers get **403**. Same slim row shape and Laravel `data` / `links` / `meta` pagination as `GET /approvals` and `GET /activities`. Sorted by `created_at` (default newest first).

**Scope**

| | |
|--|--|
| Who | Only the employee id in `LEAVE_ADMIN_EMP_ID` |
| Employees | Entire org, including the admin |
| Default statuses | `pending_approval`, `pending_conversion`, `confirmed`, `converted_to_sl`, `converted_to_el` |
| Omitted | `rejected`, `cancelled` |
| `group_id` | Optional. Filters by the person on leave’s **effective** primary group (`employee_list.group_id`, or `LEAVE_EMPLOYEE_GROUP_OVERRIDES`). Omit for all groups. Unknown id → **422**. Dropdown: `GET /admin/groups`. |

Optional `?status=` narrows to one of the allowed values (`rejected` / `cancelled` return **422**). While `pending_conversion`, type is still **VL**; after approval it becomes **SL** (`converted_to_sl`) or **EL** (`converted_to_el`) — use `?code=SL` or `?code=EL` for converted rows.

Open detail with `GET /leaves/{id}` (admin can view any leave). Conversion review stays on `GET /conversions/pending`.

**Cancel confirmed:** show Cancel on confirmed leave detail when `GET /me` `is_admin` is true. `POST /admin/leaves/{id}/cancel` with `{ "cancellation_reason": "..." }` (same reason field as employee cancel). Returns the **detail** shape (`status=cancelled`). Non-admin → **403** (`Only admin can cancel a confirmed leave.`). Not `confirmed` → **422** (`Only confirmed leave can be cancelled by admin.`). `pending_conversion` → **422** (`Leave with a pending conversion cannot be cancelled. Reject the conversion first, then cancel the confirmed leave.`). Paid credits return to available; the row then drops off the default admin list (`cancelled` is omitted). Team calendar and monthly attendance also drop it. Employee UI has no Cancel on confirmed leave.

### Admin employees (picker)

Slim org-wide employee list for admin multi-selects (Monthly Attendance Report, and any later admin picker that does not need balances). **`LEAVE_ADMIN_EMP_ID` only**. Active employees only (`employee_list.emp_status = 1`). Includes the admin. Sorted by name (`firstname`, then `surname`). No pagination.

| Method | Path | Query |
|--------|------|--------|
| GET | `/admin/employees` | Optional: `?group_id=` (`group_list.id`) |

Non-admin callers get **403**. Unknown `group_id` → **422**. Omit `group_id` for every active employee the admin may report on. `group_id` filters by the employee’s **effective** primary group (`employee_list.group_id`, or `LEAVE_EMPLOYEE_GROUP_OVERRIDES`). Dropdown: `GET /admin/groups`.

Do **not** load this from `GET /admin/balances` (paginated, heavy credit rows) or `GET /groups/{id}/members` (PIC on-behalf only).

```json
{
  "data": [
    {
      "id": 15,
      "employee_number": "15",
      "name": "Emman De Leon",
      "group": { "id": 16, "abbreviation": "SYS", "name": "Systems" }
    }
  ]
}
```

| Field | Notes |
|-------|--------|
| `employee_number` | String of `employee_list.id` (same as `id`) |
| `group` | Effective primary group. No `department` |

### Monthly Attendance Report (admin)

Confirmed-leave day matrix for **`LEAVE_ADMIN_EMP_ID` only**. The frontend page `/admin/reports/monthly-attendance` should call this API. Do **not** assemble the grid from `GET /team/calendar`, `GET /holidays`, or `GET /admin/leaves`. Team calendar is PIC-scoped and weekday-oriented; it does not classify VL / SL / Others / LWOP. This report is computed on the backend from **confirmed** `leave_info` rows (`paid_days` / `lwop_days`), not from balances.

Excel / print file generation is out of scope (frontend). No pagination.

| Method | Path | Query |
|--------|------|--------|
| GET | `/admin/reports/monthly-attendance` | `month` (`YYYY-MM`, required), `cut_off` (`monthly` \| `first_half` \| `second_half`, required), `emp_ids[]` (required, one or more employee ids, max **200**), optional `group_id` (`group_list.id`) |

Group filter dropdown: `GET /admin/groups`. Employee multi-select: `GET /admin/employees?group_id=`.

Non-admin callers get **403**. **422** when `month` / `cut_off` is missing or invalid, `emp_ids` is empty / unknown / over the cap, `group_id` is unknown, or `group_id` is sent and any selected employee’s **effective** group does not match.

`emp_ids` query: `emp_ids[]=15&emp_ids[]=22` (also accepts `emp_ids=15,22`). Duplicates are ignored.

**Cut-off windows** (every calendar day is a column, including Saturdays, Sundays, and holidays):

| `cut_off` | Period |
|-----------|--------|
| `monthly` | Day 1 through the last day of the month |
| `first_half` | 1 through `min(15, last day)` |
| `second_half` | 16 through the last day |

**Source leaves:** `emp_id` in `emp_ids`, `start_date`/`end_date` overlap the period, `status` is `confirmed`, `converted_to_sl`, or `converted_to_el`. **Omitted:** `pending_approval`, `pending_conversion`, `rejected`, `cancelled`. `converted_to_sl` counts as **SL**, not VL. `converted_to_el` counts as **EL** (`others`), not VL. Pending conversion days are absent until conversion is approved or rejected (rejected returns the leave to `confirmed`).

Expand each leave the same way duration and `/team/calendar` do, then clip to the cut-off window. VL / SL / EL / PL skip weekends and holidays. **PTB**, **LL**, and **ML** include every calendar day (weekend/holiday columns can have a cell). Paid days are assigned first; LWOP is on the last workdays of the (unclipped) range. `paid_days + lwop_days = duration`. LWOP wins the category even if the original type was VL or SL.

| Paid type | `category` |
|-----------|------------|
| VL | `vl` |
| SL (including `converted_to_sl`) | `sl` |
| EL (including `converted_to_el`), PTB, ML, PL, or any other paid type | `others` |
| Any LWOP (including LL) | `lwop` |

`day_type` `full` → `amount` `"1.00"`, `half` `null`. `half_am` / `half_pm` → `"0.50"` with matching `half`. At most one cell per employee per date. Same-category AM+PM collapse to `"1.00"`. If categories differ, **LWOP wins**. Cells are **sparse** (only days with leave); fill blanks from `dates[]`. Include every requested employee even with zero leave (`cells: []`, zero totals). Sort `rows` by `employee.name` ascending. `totals` / `grand_totals` sum **cell amounts** (decimal strings). Do not send a preformatted display string — render `"1.00"` as `1` and `"0.50"` + `half` as `0.5 (AM)` / `0.5 (PM)`.

`dates[].is_non_working` is `true` for weekends or holidays from the same `kdtholiday` source as leave duration (`GET /holidays` `is_non_working: true`).

```json
{
  "data": {
    "month": "2026-07",
    "month_label": "July 2026",
    "cut_off": "first_half",
    "cut_off_label": "First half",
    "group": { "id": 16, "abbreviation": "SYS", "name": "Systems", "dept_id": 3 },
    "employee_count": 2,
    "generated_at": "2026-08-27T13:49:00+08:00",
    "period": { "start_date": "2026-07-01", "end_date": "2026-07-15" },
    "dates": [
      { "date": "2026-07-01", "day_of_month": 1, "weekday_short": "Wed", "is_non_working": false },
      { "date": "2026-07-04", "day_of_month": 4, "weekday_short": "Sat", "is_non_working": true }
    ],
    "rows": [
      {
        "employee": {
          "id": 15,
          "employee_number": "15",
          "name": "Emman De Leon",
          "group": { "id": 16, "abbreviation": "SYS", "name": "Systems" }
        },
        "cells": [
          { "date": "2026-07-03", "category": "vl", "amount": "1.00", "half": null, "is_non_working": false },
          { "date": "2026-07-06", "category": "sl", "amount": "0.50", "half": "am", "is_non_working": false }
        ],
        "totals": { "vl": "1.00", "sl": "0.50", "others": "0.00", "lwop": "0.00" }
      }
    ],
    "grand_totals": { "vl": "1.00", "sl": "0.50", "others": "0.00", "lwop": "0.00" }
  }
}
```

| Field | Notes |
|-------|--------|
| `group` | Same shape as `GET /admin/groups` (`id` / `abbreviation` / `name` / `dept_id`), or `null` when All Groups |
| `employee_count` | Number of `rows` (requested employees, including zeros) |
| `dates[].weekday_short` | `Sun` \| `Mon` \| `Tue` \| `Wed` \| `Thu` \| `Fri` \| `Sat` |
| `cells[].category` | `vl` \| `sl` \| `others` \| `lwop` |
| `cells[].amount` | `"1.00"` or `"0.50"` only |
| `cells[].half` | `am` \| `pm` when amount is `"0.50"`, otherwise `null` |

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
| `employment_status` | Optional. Derived from `emp_prof.fldDesig` → `designation_list` (**not** `emp_status`). Falls back to `employee_list.designation` when `fldDesig` is missing or unknown. Omit for all three. Invalid value → **422**. |
| `q` | Optional. Search bar. Case-insensitive **contains** match on full name (`firstname` + space + `surname`) or employee number (`employee_list.id`, same as `employee.employee_number`). Combines with the other filters (AND). Omit or whitespace-only → no search. Max 100 characters. |
| `sort` | Optional. `asc` (default) or `desc`. Orders by employee number (`employee_list.id`). Numeric, not string order. Invalid value → **422**. |

`employment_status` mapping (`config/leave.php` → `employment`). IDs are resolved from `emp_prof.fldDesig` acronyms (`PDE`=8, `PSE`=20, `PIT-SS`=54, `CDE`=9, `CSAD`=49, `CTE`=51, `CSE`=53):

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

**POST** — HR correction of **available** credits. Writes a `manual_adjustment` ledger row and a solo `credits_adjusted` admin-log row. Non-admin callers get **403**. Success is **200** with **one** employee row in the same shape as a GET item (`{ "data": { "employee", "employment_status", "balances" } }`) so the table can refresh that line.

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
| Employees | Active **regulars** only (same `emp_prof.fldDesig` mapping as admin balances). Must have `date_hired` on or before `as_of`. |
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

**POST** — writes `annual_entitlement` ledger rows (VL and SL) and **one summarized admin-log row per leave type granted** (`annual_credits_distributed`, employee **"Multiple Employees"**). All-or-nothing for the `emp_ids` list. Server recomputes grants from tenure; client amounts are ignored (not accepted).

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

**As-of** is **today** (Asia/Manila). Regularization date is derived as `date_hired + 6 months` (no DB column). Designation is **ignored** for regular vs probationary (leaders may lag); **contractual** designations (from `emp_prof.fldDesig`) are excluded (admin adjusts those manually).

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

EL is **not** granted here (hire-time via `POST /admin/balances`).

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
      "vl_grant": "7.50",
      "sl_grant": "7.50",
      "ptb_grant": "180.00",
      "vl_after": "7.50",
      "sl_after": "7.50",
      "ptb_after": "180.00",
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

Select table: `date_hired`, `regularization_date`, `months_of_service`. Review table: `months_prorated`, `vl_grant` / `sl_grant` / `ptb_grant` (and optional before/after from `*_available` / `*_after`). For `already_granted`, after equals current available.

**POST** — writes `regularization_entitlement` ledger rows (VL, SL, PTB) and **one summarized admin-log row per leave type granted** (`regularization_credits_distributed`). All-or-nothing for the `emp_ids` list. Server recomputes grants; client amounts are ignored.

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

If **any** selected employee already has all three types (`VL`, `SL`, `PTB`) with `regularization_entitlement` in that Manila calendar year → **422** (nothing is written). Partial (some types missing) is completed, not duplicated. Success **200**:

```json
{
  "data": [ { "...same row shape, already_granted true..." } ],
  "year": 2026,
  "as_of": "2026-08-26",
  "granted_count": 2
}
```

### Contractual entitlements (admin)

Admin-typed VL and/or SL credits for **contractual** employees. **`LEAVE_ADMIN_EMP_ID` only.** Amounts are entered on the assign screen (not formula-driven). Review is mostly client-side; Confirm POSTs the typed amounts.

| Method | Path | Query / body |
|--------|------|--------|
| GET | `/admin/contractual-entitlements` | Optional: `?q=` (name or employee number), `?group_id=` (`group_list.id`), `?emp_ids=830,831` (or `emp_ids[]=` — refresh selected rows for review), `?sort=asc\|desc` (default `asc`, by employee number), `?page=1`, `?per_page=15` (1–500) |
| POST | `/admin/contractual-entitlements` | `{ "employees": [ { "emp_id": 830, "vl_to_add": "10.00", "sl_to_add": "2.00" } ] }` — Confirm button |

Non-admin callers get **403**. GET uses the same Laravel `data` / `links` / `meta` pagination. Extra `meta` keys: `as_of` (today, Asia/Manila), `eligible_count`.

**Who appears (GET)**

| | |
|--|--|
| Employees | Active **contractuals** only (resolved designation in `[9, 49, 51, 53]` from `emp_prof.fldDesig`) |
| List | All matching contractuals (no `already_granted` — grants may be repeated) |
| `group_id` / `q` / `emp_ids` / `sort` | Same rules as other admin distribution lists |

```json
{
  "data": [
    {
      "employee": {
        "id": 830,
        "employee_number": "830",
        "name": "Kai Morales",
        "group": { "id": 12, "abbreviation": "PIP", "name": "Piping" },
        "department": { "id": 2, "abbreviation": "ENG", "name": "Engineering" }
      },
      "vl_available": "2.00",
      "sl_available": "2.00"
    }
  ],
  "meta": {
    "current_page": 1,
    "as_of": "2026-08-27",
    "eligible_count": 2
  }
}
```

Assign screen: show `vl_available` / `sl_available` as Current VL/SL; admin types VL TO ADD / SL TO ADD (either, or both). Review: before = current available, to add = typed, after = before + to add (client-side). Optionally refetch `GET ?emp_ids=` for fresh before values.

**POST** — writes `contractual_entitlement` ledger rows (one per type granted) and **one summarized admin-log row per leave type granted** (`contractual_credits_distributed`). Fixed remarks: `"Contractual leave credits"`. All-or-nothing. May be run again later (no yearly lock).

```json
{
  "employees": [
    { "emp_id": 830, "vl_to_add": "10.00", "sl_to_add": "2.00" },
    { "emp_id": 831, "vl_to_add": "5.00" }
  ]
}
```

| Field | Notes |
|-------|--------|
| `employees` | Required. 1–500 unique rows. |
| `emp_id` | Required. Active contractual → else **422**. |
| `vl_to_add` / `sl_to_add` | Optional each, but **at least one** per row. Positive, max **15**, at most 2 decimal places. Omit the unused type. |

Success **200** (includes before / grant / after after the write):

```json
{
  "data": [
    {
      "employee": { "...": "..." },
      "vl_available": "12.00",
      "sl_available": "4.00",
      "vl_before": "2.00",
      "sl_before": "2.00",
      "vl_grant": "10.00",
      "sl_grant": "2.00",
      "vl_after": "12.00",
      "sl_after": "4.00"
    }
  ],
  "as_of": "2026-08-27",
  "granted_count": 1
}
```

### Approval reminders (admin)

Nudge waiting PICs / president on `pending_approval` leaves. **`LEAVE_ADMIN_EMP_ID` only** (403 otherwise). Does not mail the admin even if they are also a PIC. Pending **SL**, **PTB**, **EL**, **LL**, **ML**, and **PL** are not included (admin is the approver; they use `/admin/leaves` / `/approvals/pending`).

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
| GET | `/team/calendar` | `from` + `to` (`YYYY-MM-DD`) required. Optional `?code=VL\|SL\|PTB\|EL\|LL\|ML\|PL`, optional `?group_id=` (`pic_groups[].id`) |

**Scope**

- Employees whose **effective primary group** is in the viewer’s `formspic.fldGroups` (**includes the viewer’s own leave**)
- Statuses: `confirmed`, `pending_conversion`, `converted_to_sl`, `converted_to_el` only (`pending_approval`, `rejected`, `cancelled` omitted)
- `pending_conversion` is still **VL**; `converted_to_sl` is **SL**; `converted_to_el` is **EL** — `?code=` filters the current type
- Optional `group_id` must be one of `GET /me` `role.pic_groups` — otherwise **403**. Omit for all PIC groups. With `group_id`, the viewer’s own leave is included only if their effective group matches that id
- Not a PIC (and no `group_id`): the viewer’s own approved leaves only
- No pagination. Max range: **62 days**. Invalid / missing `from`/`to` → **422**

Leaves overlapping the range with **no painted dates** in view are omitted. VL/SL/EL/PL skip weekends and holidays (`dates` = workdays). **PTB**, **LL**, and **ML** include weekends and holidays (`dates` = every calendar day in range, clipped to `from`–`to`).

Paint cells from `dates` (already clipped to `from`–`to`). For VL/SL/EL/PL do not paint `start_date`/`end_date` or you will put bars on weekends and holidays. For PTB, LL, and ML, `dates` **does** include those days.

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
| `dates` | Days the person is out, clipped to `from`–`to`. VL/SL/EL/PL: workdays only. PTB/LL/ML: every calendar day (weekends/holidays included). |
| `duration` | Full leave length (not clipped to `from`–`to`). Half-day is `"0.50"` with one date in `dates`. |
| `day_type` | `full`, `half_am`, or `half_pm` |
| `employee.employee_number` | String of `employee_list.id` (same as `employee.id`) |
| `employee.group` | Effective primary group (`LEAVE_EMPLOYEE_GROUP_OVERRIDES` applied) |
| `id` | `leave_info.id` — list key only (calendar bars are not clickable) |

Filter dropdowns: leave types from `leave_type.code`; groups from `GET /me` `role.pic_groups`.

---

### VL conversion (SL or EL)

| Method | Path | Body |
|--------|------|------|
| POST | `/leaves/{id}/conversion` | **multipart/form-data**: `to_code=SL\|EL` (omit = **SL**), files, `reason` (**201**) |
| GET | `/conversions/pending` | Admin inbox (SL and EL together) |
| POST | `/conversions/{id}/approve` | empty |
| POST | `/conversions/{id}/reject` | `{ "remarks": "..." }` required |
| POST | `/conversions/{id}/attachments` | Add 1+ files while `pending_review`. Total after add ≤ 5 |
| GET | `/conversions/{id}/attachments/{attachmentId}` | Download one file (employee on leave, PIC-group approvers, or admin) |
| DELETE | `/conversions/{id}/attachments/{attachmentId}` | Remove one file while `pending_review`. Cannot drop below 1 |

File field depends on `to_code` (and on the pending conversion’s `to_leave_type` when adding files later):

| Target | `to_code` | Files on request / add | After approve |
|--------|-----------|------------------------|---------------|
| Sick Leave | `SL` or omit | `med_cert[]` (1–5) | status `converted_to_sl`, type SL |
| Emergency Leave | `EL` | `leave_attachment[]` (1–5) | status `converted_to_el`, type EL; `paid_days = duration`, `lwop_days = 0` |

Sending the wrong file field → **422**. Response still lists files as `has_med_cert` / `med_certs` for both targets (same as today).

#### Business rules (UI)

- Only **confirmed VL** can be converted (`status` becomes `pending_conversion` while admin reviews). Direct-filed SL/EL cannot be converted.
- Whole leave only (no partial convert). Dates already occupied by that VL cannot be filed again as SL or EL — overlap returns **422**. Convert this leave instead.
- Only the **employee on leave** (`emp_id`) may request — not an on-behalf filer.
- **Conversion reason** required (`reason`, string, max 2000) — shown to admin without opening the files. Distinct from original VL `leave_reason` and from admin reject `remarks`.
- While `pending_review`, the employee on leave may add or remove individual files (at least one must remain; total ≤ 5). SL conversions keep `med_cert[]`; EL conversions must use `leave_attachment[]`.
- Re-request allowed after **rejected** (upload 1–5 new files and a reason; `to_code` may change; previous files are replaced; leave returns to `confirmed` on reject).
- Approve / reject: **admin only** (`LEAVE_ADMIN_EMP_ID`). Same inbox: `GET /conversions/pending`.
- Filter employee leave list with `GET /leaves?status=pending_conversion`.
- Half-day VL may convert to EL when remaining EL ≥ duration (`0.50` needs ≥ `0.50`). Direct-filed EL still requires `day_type=full`.
- **VL → EL credits:** remaining EL must be ≥ VL **duration** (not `paid_days`). Example: 5-day LWOP VL + 5 EL remaining **can** convert (restore 0 VL, deduct 5 EL). Mixed 3 paid + 2 LWOP needs ≥ 5 EL. Shortfall → **422** `{ "el": ["Remaining Emergency Leave credits (2.00) are less than this leave duration (5.00)."] }` at **request**, and again at **approve** if credits changed. Show a warning from `GET /balances?code=EL` before submit — this API does not return a warning flag.

#### Multipart example (SL — default)

```js
const form = new FormData();
form.append('med_cert[]', file);
form.append('reason', 'Acute respiratory infection');
// to_code omitted → SL

await fetch(`${API}/leaves/${leaveId}/conversion`, {
  method: 'POST',
  credentials: 'include',
  headers: { Accept: 'application/json' },
  body: form, // do not set Content-Type — browser sets multipart boundary
});
```

#### Multipart example (EL)

```js
const form = new FormData();
form.append('to_code', 'EL');
form.append('leave_attachment[]', file);
form.append('reason', 'Family emergency — supporting document attached');

await fetch(`${API}/leaves/${leaveId}/conversion`, {
  method: 'POST',
  credentials: 'include',
  headers: { Accept: 'application/json' },
  body: form,
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
  "med_certs": [
    {
      "id": 1,
      "name": "medcert.pdf",
      "url": "http://localhost:8000/api/conversions/1/attachments/1"
    }
  ],
  "reason": "Acute respiratory infection",
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

After **approve**, leave type becomes the target (`SL` or `EL`) and status becomes `converted_to_sl` or `converted_to_el`. On **reject**, leave returns to `confirmed`.

---

## Suggested UI screens

1. **My leaves** — `GET /leaves` (+ status filter) → open detail with `GET /leaves/{id}`
2. **File VL** — `POST /leaves` JSON (load `GET /holidays` for picker non-working days). Self-file: block 3+ workday VL whose start is inside 1 calendar month. On-behalf: `GET /me` `pic_groups` → `GET /groups/{id}/members` → `GET /balances?emp_id=` then `emp_id` on submit; **do not** apply the 3-day / 1-month banner on File Leave for Members.
3. **File SL** — `POST /leaves` multipart with `code=SL` and required `leave_attachment[]` (1–5 med certs). Admin approves; PICs are CC'd. Overlap with an existing VL on those dates → **422** (convert confirmed VL, or cancel pending VL first)
4. **File PTB** — `POST /leaves` multipart with `code=PTB`, `day_type=full`, required med cert. Calendar days (weekends/holidays count). Duration > remaining PTB credits → **422** (no unpaid PTB). Admin approves.
5. **File EL** — `POST /leaves` multipart with `code=EL`, `day_type=full`, required supporting document. Workdays only. Duration > remaining EL credits → **422** (no unpaid EL; typical grant is 5). Admin approves.
6. **File LL** — `POST /leaves` multipart with `code=LL`, `day_type=full`, required supporting document (`leave_attachment[]`, 1–5). 1–3 calendar months, always unpaid. No overlapping dates; another pending LL blocks a new file. Admin approves.
7. **File ML** — `POST /leaves` JSON with `code=ML`, `day_type=full`, no attachment. Female only. Pick start; end = 105th calendar day. Admin approves (paid ML, not LWOP).
8. **File PL** — `POST /leaves` multipart with `code=PL`, `day_type=full`, required Paternity Notification Form (`leave_attachment[]`, 1–5). Male only. Pick start; end = 7th workday. Max 4 confirmed takes. Admin approves.
9. **Edit pending leave** — PUT/POST `/leaves/{id}` for dates/reason. On-behalf: the member or the PIC who filed may edit. SL/PTB/EL/LL/PL files: `POST /leaves/{id}/attachments` and `DELETE /leaves/{id}/attachments/{id}`
10. **Cancel (employee)** — pending only; reason form → `POST /leaves/{id}/cancel`
11. **Approver inbox** — `GET /approvals/pending` → approve / reject (or open `GET /leaves/{id}` first)
12. **All Leave Requests (approver)** — `GET /approvals` (PIC groups; filter by status / leave type)
13. **All Leave Requests (admin)** — `GET /admin/leaves` (`LEAVE_ADMIN_EMP_ID` only; org-wide; filter by status / leave type / group). Banner: `GET /admin/reminders` → `POST /admin/reminders`. Detail: `POST /leaves/{id}/reminders`. Cancel confirmed: `POST /admin/leaves/{id}/cancel`
14. **Team Leave Calendar** — `GET /team/calendar?from=&to=` (optional `code`, `group_id` from `pic_groups`)
15. **Convert VL** — on confirmed VL, choose SL (`med_cert[]`) or EL (`to_code=EL` + `leave_attachment[]`) + conversion reason. Warn when remaining EL `<` duration (`GET /balances?code=EL`). Add/remove files while pending with `/conversions/{id}/attachments`
16. **Admin conversion inbox** — `GET /conversions/pending` → approve / reject + remarks
17. **Balances** — `GET /balances` (optional `?emp_id=` for on-behalf)
18. **Employee balances (admin)** — `GET /admin/balances` (`LEAVE_ADMIN_EMP_ID` only; org-wide; `?q=` name or employee number; `?sort=asc|desc` by employee number; filter by department / group / employment status). Dropdowns: `GET /admin/departments`, `GET /admin/groups` (`dept_id` for cascade). Adjust available: `POST /admin/balances` (`emp_id`, `code`, `available_delta`, `remarks`)
19. **Annual entitlement (admin)** — `GET /admin/annual-entitlements` (regulars; `already_granted`; tenure as of 1 Jan). Review: refetch `GET /admin/annual-entitlements?year=&emp_ids=` then Confirm `POST /admin/annual-entitlements` `{ year, emp_ids }`
20. **Newly regularized (admin)** — `GET /admin/newly-regularized` (6–12 months tenure; hired after 1 Jan; exclude contractual / annual recipients). Review: refetch `?year=&emp_ids=` then Confirm `POST /admin/newly-regularized` `{ year, emp_ids }`
21. **Contractual entitlements (admin)** — `GET /admin/contractual-entitlements` (contractuals; current VL/SL). Assign typed amounts on FE → Confirm `POST /admin/contractual-entitlements` `{ employees: [{ emp_id, vl_to_add?, sl_to_add? }] }`
22. **Activity Log** — `GET /activities` (+ leave-type filter)
23. **Administrative Log (admin)** — `GET /admin/activities` (`LEAVE_ADMIN_EMP_ID` only; org-wide; `?q=` name or number, `?group_id=`, `?code=`, `?month=YYYY-MM`). Includes cancel / edit / conversion. Batch credit grants are summarized (**Multiple Employees**); manual adjust is one employee per row.
24. **Monthly Attendance Report (admin)** — `GET /admin/groups` + `GET /admin/employees?group_id=` for filters, then `GET /admin/reports/monthly-attendance?month=&cut_off=&emp_ids[]=` (do **not** assemble from `/team/calendar`, `/holidays`, or `/admin/leaves` / `/admin/balances`)

Build order tip: start with `/me` + balances + holidays + my leaves + file VL / SL / PTB / EL / LL / ML / PL, then approvals, then conversion, then activity log.

---

## Emails

Submit / confirm / reject / cancel / conversion mail is sent by the API with no extra frontend call.

Admin reminders are triggered from the UI (`GET`/`POST /admin/reminders`, `POST /leaves/{id}/reminders`).

| Event | To | CC |
|-------|----|----|
| Leave submitted (VL) | Designated approvers | — |
| Leave submitted (SL / PTB / EL / LL / ML / PL) | Admin | Same designated approvers (file attached when the type requires one) |
| Admin reminder (bulk) | Each waiting PIC / president (generic: N pending requests need approval) | — |
| Admin reminder (single) | That waiting approver | — |
| Leave confirmed / rejected | Employee (+ filer if on-behalf) | — |
| Leave cancelled by employee (VL) | Designated approvers | — |
| Leave cancelled by employee (SL / PTB / EL / LL / ML / PL) | Admin | Same designated approvers |
| Leave cancelled by admin (confirmed) | Employee (+ filer if on-behalf) | — |
| Conversion requested | Admin | Same designated approvers (file attached) |
| Conversion approved / rejected | Employee only | — |

Controlled by `LEAVE_NOTIFICATIONS_ENABLED` (default `true`) and `MAIL_*` SMTP.

---

## Not built yet (do not wire UI)

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
