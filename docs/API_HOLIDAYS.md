# Holidays API

## Goal

Expose company holidays from **`kdtholiday`** (`kdtphdb`) so the frontend date picker can disable non-working days (e.g. Ninoy Aquino Day on `2026-08-21`), and so leave duration / validation stays consistent with the backend.

The frontend already:

- Treats **weekends** as non-working locally (`Sat` / `Sun`)
- Needs **holidays** from the API as ISO dates (`YYYY-MM-DD`)
- Uses a custom date picker (`DateFieldCustom`) that disables days when `weekdaysOnly` is on

This endpoint replaces the frontend mock in `src/mocks/workCalendar.ts`.

---

## Source table: `kdtholiday`

Connection: `kdtphdb` (read-only). Model: `App\Models\Kdtph\KdtHoliday`.

### Columns

| Column | Example | Notes |
|--------|---------|--------|
| `fldID` | `166` | Primary key |
| `fldLocation` | `KDT` | Location label |
| `fldLocID` | `1` | Location id — API always filters to **`1` (KDT)** for now |
| `fldDate` | `2026-08-21` | Calendar date (`YYYY-MM-DD`) — **do not apply timezone shifts** |
| `fldHoliday` | `Ninoy Aquino Day` | Holiday name / label (also includes ad-hoc closures, e.g. Tropical Depression, swap days) |
| `fldHolidayType` | `0` or `1` | Holiday classification (see below) |
| `fldModified` | `439` | Modifier metadata — **not exposed** on the list API |

### `fldHolidayType`

| Value | Likely meaning (PH calendar convention) | Sample |
|-------|------------------------------------------|--------|
| `0` | Regular holiday | New Year's Day, Good Friday, Labor Day, Christmas Day |
| `1` | Special non-working / special holiday | Ninoy Aquino Day, National Heroes Day, Eid'l Fitr, EDSA |

**Leave filing / working-day counting / date-picker blocking:** every row returned is a non-working day (`is_non_working: true`). This matches `LeaveDurationService`, which does **not** filter by `fldHolidayType`.

### Location scope (v1)

- Always `fldLocID = 1` (KDT). There is **no** `loc_id` query param.
- Multi-location / employee-based location can be added later when needed.
- Duration calc today also does not filter by location; revisit both together when multi-site ships.

### Sample rows

| fldID | fldLocID | fldDate | fldHoliday | fldHolidayType |
|------:|---------:|---------|--------------|---------------:|
| 171 | 1 | 2026-03-20 | Eid'l Fitr | 1 |
| 170 | 1 | 2026-04-03 | Good Friday | 0 |
| 169 | 1 | 2026-04-02 | Maundy Thursday | 0 |
| 166 | 1 | **2026-08-21** | **Ninoy Aquino Day** | 1 |
| 165 | 1 | 2026-08-31 | National Heroes Day | 1 |
| 161 | 1 | 2026-01-01 | New Year's Day | 0 |
| 160 | 1 | 2025-09-26 | Tropical Depression | 0 |
| 168 | 1 | 2025-12-22 | KDT SWAP TO IMMACULATE CONCEPCION DAY (DEC 8) | 1 |

---

## Endpoint

### List holidays in a date range

```http
GET /api/holidays?from=2026-08-01&to=2026-08-31
```

```http
GET /api/holidays?year=2026
```

**Auth:** Same as other Leave Forms API routes (`portal.auth` / portal `userID` cookie). Any authenticated employee can read.

**Query params**

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `from` | `YYYY-MM-DD` | Yes* | Inclusive start (`fldDate >= from`) |
| `to` | `YYYY-MM-DD` | Yes* | Inclusive end (`fldDate <= to`) |
| `year` | `integer` | No* | Shortcut: `YYYY-01-01` … `YYYY-12-31`. If both `from`/`to` and `year` are sent, **`from`/`to` win** |

\* Either (`from` + `to`) **or** `year` is required.

**Validation rules**

- `from` / `to` must be valid `Y-m-d` dates
- `to` must be `>= from`
- Max range: **400 days**
- `year` must be 2000–2100

**Success response** — JsonResource collection (no pagination):

```json
{
  "data": [
    {
      "id": 166,
      "date": "2026-08-21",
      "name": "Ninoy Aquino Day",
      "holiday_type": 1,
      "is_non_working": true,
      "location_id": 1,
      "location": "KDT"
    },
    {
      "id": 165,
      "date": "2026-08-31",
      "name": "National Heroes Day",
      "holiday_type": 1,
      "is_non_working": true,
      "location_id": 1,
      "location": "KDT"
    }
  ]
}
```

**Resource field mapping**

| API field | DB column | Notes |
|-----------|-----------|--------|
| `id` | `fldID` | |
| `date` | `fldDate` | Always `Y-m-d` string — no timezone conversion |
| `name` | `fldHoliday` | |
| `holiday_type` | `fldHolidayType` | `0` or `1` — raw value |
| `is_non_working` | derived | Always `true` (matches duration) |
| `location_id` | `fldLocID` | Always `1` in v1 responses |
| `location` | `fldLocation` | e.g. `KDT` |

Omit `fldModified`.

**Error responses**

| Status | When |
|--------|------|
| `401` | Unauthenticated (existing `login_url` payload) |
| `422` | Invalid / missing `from`/`to`/`year`, or range too large |

Example `422`:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "from": ["The from field is required when year is not present."],
    "to": ["The to field must be a date after or equal to from."]
  }
}
```

---

## Implementation

| Piece | Location |
|-------|----------|
| Model | `App\Models\Kdtph\KdtHoliday` (`KDT_LOCATION_ID = 1`) |
| Form request | `App\Http\Requests\Leave\IndexHolidayRequest` |
| Resource | `App\Http\Resources\HolidayResource` |
| Controller | `App\Http\Controllers\HolidayController@index` |
| Route | `GET /api/holidays` inside `portal.auth` |

**Timezone:** Store and return calendar dates only. Avoid UTC shifts that turn `2026-08-21` into `2026-08-20`.

---

## Frontend consumption

1. File Leave loads holidays for a window (e.g. today → +12–18 months, or the visible picker month).
2. Builds a `Set` of `date` where `is_non_working === true`.
3. Custom date picker disables a day if weekend **or** date ∈ that set.
4. Leave **duration / paid / LWOP** is still computed and validated on the **backend** when creating/updating leaves (frontend picker is UX only).

Related product note from `docs/FRONTEND.md`:

> Date range + day type → duration from API (workdays / holidays)

So this holidays list endpoint is for **UI blocking / display**. Duration math stays inside leave store/update (or a future `POST /leaves/preview`), using the same `kdtholiday` data.

---

## Out of scope (for now)

- Admin CRUD for holidays (POST/PUT/DELETE)
- Returning weekends from this endpoint (frontend handles weekends)
- `loc_id` query param / multi-location aggregation
- Employee-based location defaulting

---

## Acceptance checklist

- [x] `GET /api/holidays?from=&to=` reads from `kdtholiday`
- [x] Filters by `fldLocID = 1` (KDT)
- [x] Dates are `YYYY-MM-DD` strings with no timezone drift
- [x] Response is `{ "data": [ ... ] }` JsonResource collection
- [ ] Known row `fldDate = 2026-08-21` (Ninoy Aquino Day) appears when querying August 2026
- [x] Invalid range → `422`
- [x] Unauthenticated → existing `401` + `login_url` behavior
- [x] Leave create still rejects invalid working-day ranges even if the client bypasses the picker
- [x] `is_non_working` matches existing leave duration logic (all rows)

---

## Optional follow-ups (not required for v1)

1. Align `LeaveDurationService` holiday lookup with `fldLocID = 1` when multi-site data appears.
2. `POST /api/leaves/preview` returning `{ duration, paid_days, lwop_days }` using the same `kdtholiday` table.
3. Short server/HTTP cache (holiday rows change infrequently).
4. Employee / multi-location filtering when a location-aware app exists.
