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
| Leave `status` | `pending_approval`, `partially_approved`, `confirmed`, `rejected`, `cancelled`, `converted_to_sl` |
| Conversion `status` | `pending_review`, `approved`, `rejected` |

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

### Leaves (employee)

| Method | Path | Body / notes |
|--------|------|----------------|
| GET | `/leaves` | Query: `status`, `per_page` (1–50, default 15) |
| POST | `/leaves` | JSON — file VL (**201**) |
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

#### Leave resource (main fields)

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
  "approval_requirements": [
    {
      "display_order": 1,
      "allowed_levels": [1, 2, 3, 4],
      "required_approver_type": null,
      "is_satisfied": false,
      "satisfied_at": null,
      "history": null
    }
  ],
  "confirmed_at": null,
  "created_at": "2026-07-28T08:00:00+00:00"
}
```

**Edit rules:** only while status is `pending_approval` and no approval history yet.  
**Cancel:** open requests (pending / partially approved); reason required.

---

### Approvals (designated PIC)

| Method | Path | Body |
|--------|------|------|
| GET | `/approvals/pending` | — |
| POST | `/leaves/{id}/approve` | empty |
| POST | `/leaves/{id}/reject` | `{ "remarks": "..." }` required |

Who appears in pending / can act: formspic PICs whose `fldGroups` include the employee’s **primary** group (`employee_list.group_id`), at allowed levels for that leave. One approval confirms VL.

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

- Only **confirmed VL** can be converted.
- Only the **employee on leave** (`emp_id`) may request — not an on-behalf filer.
- Med cert required: pdf / jpg / jpeg / png (default max 5120 KB).
- Re-request allowed after **rejected** (upload a new med cert).
- Approve / reject: **admin only** (`LEAVE_ADMIN_EMP_ID`).

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
    "status": "confirmed",
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

After **approve**, leave status becomes `converted_to_sl` and leave type becomes SL.

---

## Suggested UI screens

1. **My leaves** — `GET /leaves` (+ status filter)
2. **File VL** — `POST /leaves`
3. **Edit pending VL** — PUT/POST `/leaves/{id}`
4. **Cancel** — reason form → cancel endpoint
5. **Approver inbox** — `GET /approvals/pending` → approve / reject
6. **Convert VL → SL** — on confirmed VL, upload med cert
7. **Admin conversion inbox** — `GET /conversions/pending` → approve / reject + remarks
8. **Balances** — `GET /balances`

Build order tip: start with `/me` + balances + my leaves + file VL, then approvals, then conversion.

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
| `docs/SCHEMA.md` | Table / enum reference |
| `docs/LEGACY_NOTES.md` | Legacy vs revamp differences |
