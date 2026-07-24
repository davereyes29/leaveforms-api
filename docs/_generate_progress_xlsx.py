"""Generate Leave Forms Revamp progress checklist Excel workbook."""

from collections import OrderedDict
from datetime import date, timedelta

from openpyxl import Workbook
from openpyxl.styles import Alignment, Border, Font, PatternFill, Side

SCORE = {"Done": 1.0, "Partial": 0.5, "Todo": 0.0, "N/A": None}

SECTION_COLORS = {
    "Foundation": "FFF4C7A1",
    "Employee Leave Filing": "FFA8D4EF",
    "Approver / PIC": "FF7EB8D9",
    "Balance & Credits": "FFA8D5A2",
    "Admin / Special Flows": "FFC9B1E0",
}

MODULE_HEADER_TINT = {
    "Foundation": "FFFBE8D8",
    "Employee Leave Filing": "FFE3F2FB",
    "Approver / PIC": "FFD5EAF5",
    "Balance & Credits": "FFE3F5E1",
    "Admin / Special Flows": "FFF0E6F8",
}

STATUS_FILL = {
    "Done": PatternFill("solid", fgColor="FFC6EFCE"),
    "Partial": PatternFill("solid", fgColor="FFFFEB9C"),
    "Todo": PatternFill("solid", fgColor="FFFFC7CE"),
    "N/A": PatternFill("solid", fgColor="FFD9D9D9"),
}
STATUS_FONT = {
    "Done": Font(color="FF006100", bold=True),
    "Partial": Font(color="FF9C5700", bold=True),
    "Todo": Font(color="FF9C0006", bold=True),
    "N/A": Font(color="FF595959", bold=True),
}

# Row: section, module, task, status, notes, phase, august_dev
# phase: 1-5 for Aug development, "post" for after Aug, None for N/A
# Updated 2026-07-22 — legacy leaves stay in legacy; balances entered manually
ROWS = [
    # Foundation — Schema (Phase 1)
    ("Foundation", "Schema & Data Model", "Migrations — 8 revamp tables (leave_type → leave_conversion)", "Done", "migrations present", 1, True),
    ("Foundation", "Schema & Data Model", "Indexes & unique constraints (SCHEMA.md)", "Done", "", 1, True),
    ("Foundation", "Schema & Data Model", "Eloquent models + PHP backed enums", "Done", "", 1, True),
    ("Foundation", "Schema & Data Model", "LeaveTypeSeeder (VL, SL, EL, ML, PL, PTB, LL)", "Done", "", 1, True),
    ("Foundation", "Schema & Data Model", "kdtphdb read models (employee, formspic, holiday, login)", "Done", "", 1, True),
    # Foundation — Auth
    ("Foundation", "Portal Auth & Access", "Dual DB setup — formsdb + kdtphdb (read-only)", "Done", "", 1, True),
    ("Foundation", "Portal Auth & Access", "Portal cookie auth (userID → kdtlogin)", "Done", "", 1, True),
    ("Foundation", "Portal Auth & Access", "Routes — GET /api/me, GET /api/health", "Done", "", 1, True),
    ("Foundation", "Portal Auth & Access", "Config — president_emp_id, CORS, attachments, notifications", "Done", "", 1, True),
    ("Foundation", "Portal Auth & Access", "Staging/prod portal cookie & env verify with infra", "Todo", "integration / infra — not legacy leave import", 1, True),
    ("Foundation", "Portal Auth & Access", "Testing — auth middleware & health checks", "Todo", "after Aug development", "post", False),
    # Employee — Submit (Phase 2)
    ("Employee Leave Filing", "Submit Leave (VL)", "Routes & controller — POST /leaves", "Done", "", 2, True),
    ("Employee Leave Filing", "Submit Leave (VL)", "Validation — StoreLeaveRequest + attachment rules", "Done", "", 2, True),
    ("Employee Leave Filing", "Submit Leave (VL)", "Duration calc — workdays, holidays, half-day", "Done", "", 2, True),
    ("Employee Leave Filing", "Submit Leave (VL)", "paid_days / lwop_days split from available", "Done", "", 2, True),
    ("Employee Leave Filing", "Submit Leave (VL)", "Snapshot approval requirements at submit", "Done", "", 2, True),
    ("Employee Leave Filing", "Submit Leave (VL)", "Reserve paid credits (leave_reserved)", "Done", "", 2, True),
    ("Employee Leave Filing", "Submit Leave (VL)", "File on behalf (filed_by_emp_id / Group Leader)", "Done", "", 2, True),
    ("Employee Leave Filing", "Submit Leave (VL)", "Attachments — store / replace / download", "Done", "", 2, True),
    ("Employee Leave Filing", "Submit Leave (VL)", "Overlap validation + activity submitted", "Done", "", 2, True),
    ("Employee Leave Filing", "Submit Leave (VL)", "Email notify approvers on submit", "Done", "LeaveSubmittedMail", 2, True),
    ("Employee Leave Filing", "Submit Leave (VL)", "Testing — submit / LWOP / on-behalf cases", "Todo", "after Aug development", "post", False),
    # Employee — List
    ("Employee Leave Filing", "My Leaves List", "Routes & controller — GET /leaves", "Done", "", 2, True),
    ("Employee Leave Filing", "My Leaves List", "Data fetch — paginate for employee", "Done", "", 2, True),
    ("Employee Leave Filing", "My Leaves List", "LeaveResource response shape", "Done", "", 2, True),
    ("Employee Leave Filing", "My Leaves List", "Testing — list filters & pagination", "Todo", "after Aug development", "post", False),
    # Employee — Modify
    ("Employee Leave Filing", "Modify Leave (while pending)", "Routes & controller — PUT/POST /leaves/{id}", "Done", "POST preferred for multipart", 2, True),
    ("Employee Leave Filing", "Modify Leave (while pending)", "Validation — only if no requirement satisfied", "Done", "", 2, True),
    ("Employee Leave Filing", "Modify Leave (while pending)", "Release + re-reserve when paid_days changes", "Done", "", 2, True),
    ("Employee Leave Filing", "Modify Leave (while pending)", "Activity — modified", "Done", "", 2, True),
    ("Employee Leave Filing", "Modify Leave (while pending)", "Block edit after partial approval", "Done", "", 2, True),
    ("Employee Leave Filing", "Modify Leave (while pending)", "Testing — edit / blocked-edit cases", "Todo", "after Aug development", "post", False),
    # Employee — Cancel
    ("Employee Leave Filing", "Cancel Leave", "Routes & controller — POST /leaves/{id}/cancel", "Done", "", 2, True),
    ("Employee Leave Filing", "Cancel Leave", "Validation — cancellation_reason required", "Done", "", 2, True),
    ("Employee Leave Filing", "Cancel Leave", "Release reserved credits", "Done", "", 2, True),
    ("Employee Leave Filing", "Cancel Leave", "Activity — cancelled (no approval_history row)", "Done", "", 2, True),
    ("Employee Leave Filing", "Cancel Leave", "Testing — cancel pending / partially approved", "Todo", "after Aug development", "post", False),
    # Approver — Pending (Phase 3)
    ("Approver / PIC", "Pending Approvals Dashboard", "Routes & controller — GET /approvals/pending", "Done", "", 3, True),
    ("Approver / PIC", "Pending Approvals Dashboard", "Data aggregation — formspic group scope", "Done", "", 3, True),
    ("Approver / PIC", "Pending Approvals Dashboard", "Response — actionable leaves for approver", "Done", "", 3, True),
    ("Approver / PIC", "Pending Approvals Dashboard", "Stakeholder — special group rules (KRM / checkTier)", "Todo", "only if FE inbox needs it in Aug", 3, True),
    ("Approver / PIC", "Pending Approvals Dashboard", "Testing — visibility by role / group", "Todo", "after Aug development", "post", False),
    # Approver — Decide
    ("Approver / PIC", "Approve / Reject Leave", "Routes & controller — approve / reject", "Done", "", 3, True),
    ("Approver / PIC", "Approve / Reject Leave", "Approval policy matrix (regular → P4 / president)", "Done", "", 3, True),
    ("Approver / PIC", "Approve / Reject Leave", "Approve path — partially_approved → confirmed", "Done", "", 3, True),
    ("Approver / PIC", "Approve / Reject Leave", "Reject path — close leave + release reserve", "Done", "", 3, True),
    ("Approver / PIC", "Approve / Reject Leave", "First actor wins (UNIQUE requirement_id)", "Done", "", 3, True),
    ("Approver / PIC", "Approve / Reject Leave", "Finalize — confirmPaidDays / skip ledger if full LWOP", "Done", "", 3, True),
    ("Approver / PIC", "Approve / Reject Leave", "President fallback when no PIC for group", "Partial", "config + resolver; verify staging emp ID", 3, True),
    ("Approver / PIC", "Approve / Reject Leave", "Notify employee on confirm", "Done", "LeaveConfirmedMail", 3, True),
    ("Approver / PIC", "Approve / Reject Leave", "Notify filer / employee on reject", "Todo", "", 2, True),
    ("Approver / PIC", "Approve / Reject Leave", "Notify next-tier approvers after partial approve", "Todo", "", 2, True),
    ("Approver / PIC", "Approve / Reject Leave", "Testing — matrix, reject, finalize edge cases", "Todo", "after Aug development", "post", False),
    # Balance — Ledger
    ("Balance & Credits", "Balance Ledger & Buckets", "Routes & controller — GET /balances", "Done", "", 2, True),
    ("Balance & Credits", "Balance Ledger & Buckets", "reserve / confirm / releaseReservation", "Done", "", 2, True),
    ("Balance & Credits", "Balance Ledger & Buckets", "applyInitialBalance + applyManualAdjustment", "Done", "service methods exist", 4, True),
    ("Balance & Credits", "Balance Ledger & Buckets", "Dev seed — leave:seed-balance", "Done", "local only", 1, True),
    ("Balance & Credits", "Balance Ledger & Buckets", "Admin/HR API — set opening balance (manual from legacy)", "Todo", "manual entry; no bulk legacy import", 4, True),
    ("Balance & Credits", "Balance Ledger & Buckets", "Admin/HR API — manual adjust with remarks", "Todo", "expose applyManualAdjustment", 4, True),
    ("Balance & Credits", "Balance Ledger & Buckets", "applyAnnualEntitlement (15/15 or 15/18 by tenure)", "Todo", "if needed for Aug admin/FE", 4, True),
    ("Balance & Credits", "Balance Ledger & Buckets", "applyMonthlyAccrual (+1.25 VL/SL)", "Todo", "if needed for Aug admin/FE", 4, True),
    ("Balance & Credits", "Balance Ledger & Buckets", "Scheduled jobs — entitlement / accrual", "Todo", "can slip past Aug if manual grant OK", 4, True),
    ("Balance & Credits", "Balance Ledger & Buckets", "reverseConfirmedLeave (admin undo)", "Todo", "", 4, True),
    ("Balance & Credits", "Balance Ledger & Buckets", "Testing — ledger invariants & locking", "Todo", "after Aug development", "post", False),
    # Opening balances policy (was legacy migration)
    ("Balance & Credits", "Opening Balances (manual)", "Decision — legacy and revamp stay separate apps", "Done", "no shared leave history", 4, True),
    ("Balance & Credits", "Opening Balances (manual)", "Decision — old leaves do NOT appear in revamp", "Done", "revamp starts clean", 4, True),
    ("Balance & Credits", "Opening Balances (manual)", "Decision — opening credits entered manually from legacy", "Done", "HR keys available VL/SL etc.", 4, True),
    ("Balance & Credits", "Opening Balances (manual)", "Bulk leave_confirmation → leave_balance migration script", "N/A", "not using automated data migration", None, False),
    ("Balance & Credits", "Opening Balances (manual)", "Import / carry over open pending legacy leave requests", "N/A", "employees re-file in revamp if needed", None, False),
    ("Balance & Credits", "Opening Balances (manual)", "Guard — skip corrupted negative legacy durations", "N/A", "no legacy leave import", None, False),
    ("Balance & Credits", "Opening Balances (manual)", "Testing — migration dry-run / idempotency", "N/A", "no migration script", None, False),
    # Admin — Conversion (Phase 4)
    ("Admin / Special Flows", "VL → SL Conversion", "Schema — leave_conversion table + model + enums", "Done", "", 4, True),
    ("Admin / Special Flows", "VL → SL Conversion", "Routes — employee request + med cert upload", "Todo", "aligns with FE admin conversion", 4, True),
    ("Admin / Special Flows", "VL → SL Conversion", "Admin review — approve / reject endpoints", "Todo", "", 4, True),
    ("Admin / Special Flows", "VL → SL Conversion", "Balance — vl_to_sl_restore + vl_to_sl_deduct", "Todo", "", 4, True),
    ("Admin / Special Flows", "VL → SL Conversion", "Activity — conversion_requested / approved / rejected", "Todo", "", 4, True),
    ("Admin / Special Flows", "VL → SL Conversion", "leave_info → converted_to_sl + type SL", "Todo", "", 4, True),
    ("Admin / Special Flows", "VL → SL Conversion", "Testing — approve / reject / balance side effects", "Todo", "after Aug development", "post", False),
    # Admin — Other types
    ("Admin / Special Flows", "Other Leave Types (EL, ML, PL, PTB, LL)", "Leave type codes seeded", "Done", "", 4, True),
    ("Admin / Special Flows", "Other Leave Types (EL, ML, PL, PTB, LL)", "Submit flows beyond VL", "Todo", "if FE needs in Aug Phase 4", 4, True),
    ("Admin / Special Flows", "Other Leave Types (EL, ML, PL, PTB, LL)", "Admin requirement rows on original request", "Todo", "", 4, True),
    ("Admin / Special Flows", "Other Leave Types (EL, ML, PL, PTB, LL)", "Stakeholder — exact requirement sets per type", "Todo", "open in APPROVAL_RULES", 4, True),
    ("Admin / Special Flows", "Other Leave Types (EL, ML, PL, PTB, LL)", "Testing — per-type approval paths", "Todo", "after Aug development", "post", False),
    # Admin — Reports
    ("Admin / Special Flows", "Reports & Visibility", "Pending approvals scoped to formspic groups", "Partial", "pending list exists; full reports no", 4, True),
    ("Admin / Special Flows", "Reports & Visibility", "Report API — sum confirmed leave_info (not balance)", "Todo", "revamp leaves only", 4, True),
    ("Admin / Special Flows", "Reports & Visibility", "Quarterly absence warnings (informational)", "Todo", "", 4, True),
    ("Admin / Special Flows", "Reports & Visibility", "Stakeholder — report access / special groups", "Todo", "", 4, True),
    ("Admin / Special Flows", "Reports & Visibility", "Testing — report totals & access", "Todo", "after Aug development", "post", False),
    # Notifications remaining
    ("Admin / Special Flows", "Notifications (remaining)", "Submit notify to approvers", "Done", "LeaveNotificationService", 2, True),
    ("Admin / Special Flows", "Notifications (remaining)", "Confirm notify to employee / filer", "Done", "LeaveConfirmedMail", 3, True),
    ("Admin / Special Flows", "Notifications (remaining)", "Reject notify to employee / filer", "Todo", "", 2, True),
    ("Admin / Special Flows", "Notifications (remaining)", "Notify next approvers after partial approve", "Todo", "", 2, True),
    ("Admin / Special Flows", "Notifications (remaining)", "Conversion review outcome notify", "Todo", "", 4, True),
    ("Admin / Special Flows", "Notifications (remaining)", "Testing — mail / notification payloads", "Todo", "after Aug development", "post", False),
]

# Tentative schedule — started first week of July; dates reflect actual backend progress
# Git anchors: 2026-07-07 initial leave APIs · 2026-07-17 modify/attachments · 2026-07-21 notifications
# Scope note: Phases 2–3 are VL-only so far. Other leave types (EL/ML/PL/PTB/LL) remain open.
SCHEDULE_PHASES = [
    {
        "phase": "Phase 1 — Foundation (development)",
        "start": date(2026, 7, 1),
        "end": date(2026, 7, 10),
        "status": "Completed",
        "focus": "Portal auth, /me, /health, dual DB, CORS, schema/models, leave config",
        "notes": "Started first week of July (2026-07-01). Schema, dual DB, portal cookie auth, models/enums essentially done. Leave type codes seeded (incl. non-VL) but submit flows for them are not Phase 1.",
    },
    {
        "phase": "Phase 2 — Employee APIs (development)",
        "start": date(2026, 7, 7),
        "end": date(2026, 8, 13),
        "status": "Partial — VL only",
        "focus": "VL: submit, list, edit, cancel, attachments, balances read, on-behalf, submit notify. Still open: other leave-type filing (EL/ML/PL/PTB/LL), reject/next-tier notify leftovers",
        "notes": "VL employee path largely shipped (Jul 7 + Jul 17). NOT complete for other leave types — only VL submit exists. End date aligned with FE Phase 2 so remaining non-VL filing can land here if prioritized; otherwise carry into Phase 4.",
    },
    {
        "phase": "Phase 3 — Approver APIs (development)",
        "start": date(2026, 7, 7),
        "end": date(2026, 8, 21),
        "status": "Partial — VL only",
        "focus": "VL: pending inbox, approve/reject, finalize/confirm, file-for-member, confirm email. Still open: admin-requirement paths for other types, president verify, special group rules",
        "notes": "VL approval path largely shipped (Jul 7 + Jul 21 confirm mail). Other leave types that need admin (or different matrices) are not done. End date aligned with FE Phase 3.",
    },
    {
        "phase": "Phase 4 — Admin APIs (development)",
        "start": date(2026, 7, 22),
        "end": date(2026, 8, 28),
        "status": "Started / In progress",
        "focus": "VL→SL conversion, admin review, manual opening balances/adjust, reports; other leave types + admin requirements if FE needs; finish notify leftovers",
        "notes": "Main remaining Aug work. Includes non-VL leave types when prioritized with FE. No legacy leave import — opening balances entered manually.",
    },
    {
        "phase": "Phase 5 — Development complete (commitment)",
        "start": date(2026, 8, 28),
        "end": date(2026, 8, 31),
        "status": "Planned",
        "focus": "Backend API development targeted for completion (August commitment)",
        "notes": "End of August = development only — not full prod go-live. Same meaning as FE Phase 5.",
    },
]

AFTER_ROWS = [
    (
        "Frontend ↔ backend integration (parallel / TBD)",
        None,
        None,
        "Ongoing with frontend partner — no fixed day count yet. Update when APIs and cookie/session are ready in each env.",
    ),
    (
        "Testing (after development)",
        date(2026, 9, 1),
        None,
        "Planned to start on/after 2026-09-01. End date TBD. Not part of August commitment.",
    ),
    (
        "Documentation (after development)",
        date(2026, 9, 1),
        None,
        "Planned to start on/after 2026-09-01. End date TBD. Include manual opening-balance process notes.",
    ),
]


def weekday_count(start: date, end: date) -> int:
    n = 0
    cur = start
    while cur <= end:
        if cur.weekday() < 5:
            n += 1
        cur += timedelta(days=1)
    return n


def active_statuses(rows):
    return [r[3] for r in rows if r[3] != "N/A"]


def pct(statuses):
    scored = [s for s in statuses if SCORE.get(s) is not None]
    if not scored:
        return 0
    earned = sum(SCORE[s] for s in scored)
    return round(100 * earned / len(scored))


def apply_header(cell, fill, font, thin):
    cell.fill = fill
    cell.font = font
    cell.alignment = Alignment(horizontal="center", wrap_text=True, vertical="center")
    cell.border = thin


def main():
    thin = Border(
        left=Side(style="thin", color="FFB0B0B0"),
        right=Side(style="thin", color="FFB0B0B0"),
        top=Side(style="thin", color="FFB0B0B0"),
        bottom=Side(style="thin", color="FFB0B0B0"),
    )
    header_fill = PatternFill("solid", fgColor="FF2F5496")
    header_font = Font(color="FFFFFFFF", bold=True, size=11)
    title_font = Font(bold=True, size=16, color="FF1F4E79")
    section_font = Font(bold=True, size=11)
    phase_fills = {
        1: PatternFill("solid", fgColor="FFF4C7A1"),
        2: PatternFill("solid", fgColor="FFA8D4EF"),
        3: PatternFill("solid", fgColor="FF7EB8D9"),
        4: PatternFill("solid", fgColor="FFC9B1E0"),
        5: PatternFill("solid", fgColor="FFA8D5A2"),
    }

    modules = OrderedDict()
    sections = OrderedDict()
    for section, module, task, status, notes, phase, august_dev in ROWS:
        key = (section, module)
        modules.setdefault(key, []).append((task, status, notes, phase, august_dev))
        sections.setdefault(section, [])
        if module not in sections[section]:
            sections[section].append(module)

    all_active = active_statuses(ROWS)
    overall_pct = pct(all_active)
    done_n = all_active.count("Done")
    partial_n = all_active.count("Partial")
    todo_n = all_active.count("Todo")
    na_n = sum(1 for r in ROWS if r[3] == "N/A")

    aug_rows = [r for r in ROWS if r[6] and r[3] != "N/A"]
    aug_statuses = [r[3] for r in aug_rows]
    aug_pct = pct(aug_statuses)

    wb = Workbook()

    # ========== Schedule (like FE) ==========
    ws_s = wb.active
    ws_s.title = "Schedule"

    ws_s["A1"] = "Tentative Schedule — Backend API development (to end of August 2026)"
    ws_s["A1"].font = title_font
    ws_s.merge_cells("A1:G1")

    ws_s["A2"] = (
        "Dates adjusted to actual progress. Started first week of July (2026-07-01). Weekdays only. "
        f"Updated {date.today().isoformat()}"
    )
    ws_s["A2"].font = Font(italic=True, color="FF666666", size=10)
    ws_s.merge_cells("A2:G2")

    ws_s["A3"] = (
        "Phases 2–3 are NOT fully complete: VL path is largely done; other leave types (EL/ML/PL/PTB/LL) still open. "
        "Phase 1 done. Phase 4 = main remaining Aug work (conversion, manual balances, reports, non-VL). "
        "August = development only through 2026-08-31. Testing & docs from 2026-09-01. "
        "Legacy stays separate — no leave history import; opening balances entered manually."
    )
    ws_s["A3"].font = Font(color="FF666666", size=9)
    ws_s.merge_cells("A3:G3")
    ws_s.row_dimensions[3].height = 48

    headers = ["Phase", "Start", "End", "Days (weekdays)", "Status", "Focus", "Notes"]
    for col, h in enumerate(headers, 1):
        apply_header(ws_s.cell(5, col, h), header_fill, header_font, thin)

    status_fill = {
        "Completed": PatternFill("solid", fgColor="FFC6EFCE"),
        "Completed (leftovers)": PatternFill("solid", fgColor="FFC6EFCE"),
        "Partial — VL only": PatternFill("solid", fgColor="FFFFEB9C"),
        "Started / In progress": PatternFill("solid", fgColor="FFFFEB9C"),
        "Upcoming": PatternFill("solid", fgColor="FFDDEBF7"),
        "Planned": PatternFill("solid", fgColor="FFE2EFDA"),
    }

    r = 6
    for i, phase in enumerate(SCHEDULE_PHASES, 1):
        days = weekday_count(phase["start"], phase["end"])
        ws_s.cell(r, 1, phase["phase"]).fill = phase_fills.get(i, PatternFill())
        ws_s.cell(r, 1).font = Font(bold=True)
        ws_s.cell(r, 2, phase["start"].isoformat())
        ws_s.cell(r, 3, phase["end"].isoformat())
        ws_s.cell(r, 4, days).alignment = Alignment(horizontal="center")
        st = ws_s.cell(r, 5, phase["status"])
        st.fill = status_fill.get(phase["status"], PatternFill())
        st.font = Font(bold=True)
        st.alignment = Alignment(horizontal="center", wrap_text=True)
        ws_s.cell(r, 6, phase["focus"])
        ws_s.cell(r, 7, phase["notes"])
        for c in range(1, 8):
            ws_s.cell(r, c).border = thin
            ws_s.cell(r, c).alignment = Alignment(vertical="center", wrap_text=True)
        ws_s.row_dimensions[r].height = 48
        r += 1

    r += 1
    ws_s.cell(r, 1, "After / alongside development (no fixed end dates yet)").font = Font(
        bold=True, size=12, color="FF1F4E79"
    )
    ws_s.merge_cells(start_row=r, start_column=1, end_row=r, end_column=7)
    r += 1

    for col, h in enumerate(["Item", "Start", "End", "Days (weekdays)", "Status", "Notes", ""], 1):
        apply_header(ws_s.cell(r, col, h), header_fill, header_font, thin)
    r += 1

    for item, start, end, notes in AFTER_ROWS:
        ws_s.cell(r, 1, item).font = Font(bold=True)
        ws_s.cell(r, 2, start.isoformat() if start else "TBD / parallel")
        ws_s.cell(r, 3, end.isoformat() if end else "TBD")
        ws_s.cell(r, 4, "—" if start is None or end is None else weekday_count(start, end))
        ws_s.cell(r, 4).alignment = Alignment(horizontal="center")
        ws_s.cell(r, 5, "Planned")
        ws_s.cell(r, 6, notes)
        for c in range(1, 8):
            ws_s.cell(r, c).border = thin
            ws_s.cell(r, c).alignment = Alignment(vertical="center", wrap_text=True)
        ws_s.row_dimensions[r].height = 40
        r += 1

    r += 1
    ws_s.cell(r, 1, "August development progress (excludes Testing, Docs, N/A)").font = Font(
        bold=True, size=12, color="FF1F4E79"
    )
    r += 1
    ws_s.cell(r, 1, "Aug-scope %")
    ws_s.cell(r, 2, aug_pct / 100).number_format = "0%"
    ws_s.cell(r, 2).font = Font(bold=True, size=14, color="FF1F4E79")
    ws_s.cell(r, 3, f"{aug_statuses.count('Done')} done / {aug_statuses.count('Partial')} partial / {aug_statuses.count('Todo')} todo of {len(aug_statuses)} Aug-scope tasks")

    ws_s.column_dimensions["A"].width = 42
    ws_s.column_dimensions["B"].width = 14
    ws_s.column_dimensions["C"].width = 14
    ws_s.column_dimensions["D"].width = 16
    ws_s.column_dimensions["E"].width = 18
    ws_s.column_dimensions["F"].width = 52
    ws_s.column_dimensions["G"].width = 52

    # ========== Summary ==========
    ws = wb.create_sheet("Summary", 1)

    ws["A1"] = "Leave Forms Revamp — Backend Progress Checklist"
    ws["A1"].font = title_font
    ws.merge_cells("A1:G1")

    ws["A2"] = (
        f"Scored against Laravel API · docs/*.md · Updated {date.today().isoformat()} · "
        "N/A tasks excluded from %. Testing/docs excluded from August schedule."
    )
    ws["A2"].font = Font(italic=True, color="FF666666", size=10)
    ws.merge_cells("A2:G2")

    ws["A3"] = (
        "Policy: legacy app remains separate; old leaves do not appear in revamp; "
        "opening balances are entered manually (no automated leave_confirmation migration)."
    )
    ws["A3"].font = Font(color="FF666666", size=9)
    ws.merge_cells("A3:G3")

    ws["A5"] = "Overall % (excl. N/A)"
    ws["A5"].font = Font(bold=True, size=12)
    ws["B5"] = overall_pct / 100
    ws["B5"].number_format = "0%"
    ws["B5"].font = Font(bold=True, size=18, color="FF1F4E79")

    ws["C5"] = "Aug-scope %"
    ws["C5"].font = Font(bold=True, size=12)
    ws["D5"] = aug_pct / 100
    ws["D5"].number_format = "0%"
    ws["D5"].font = Font(bold=True, size=18, color="FF548235")

    ws["A6"] = "Done"
    ws["B6"] = done_n
    ws["C6"] = "Partial"
    ws["D6"] = partial_n
    ws["E6"] = "Todo"
    ws["F6"] = todo_n
    ws["G6"] = f"N/A {na_n}"
    ws["B6"].fill = STATUS_FILL["Done"]
    ws["D6"].fill = STATUS_FILL["Partial"]
    ws["F6"].fill = STATUS_FILL["Todo"]
    ws["G6"].fill = STATUS_FILL["N/A"]

    headers = ["Section", "Modules", "Tasks*", "Done", "Partial", "Todo", "Progress %"]
    for col, h in enumerate(headers, 1):
        apply_header(ws.cell(8, col, h), header_fill, header_font, thin)

    row = 9
    for section, mod_list in sections.items():
        statuses = active_statuses([r for r in ROWS if r[0] == section])
        done = statuses.count("Done")
        partial = statuses.count("Partial")
        todo = statuses.count("Todo")
        p = pct(statuses)
        ws.cell(row, 1, section).fill = PatternFill("solid", fgColor=SECTION_COLORS[section])
        ws.cell(row, 1).font = section_font
        ws.cell(row, 2, len(mod_list))
        ws.cell(row, 3, len(statuses))
        ws.cell(row, 4, done)
        ws.cell(row, 5, partial)
        ws.cell(row, 6, todo)
        ws.cell(row, 7, p / 100).number_format = "0%"
        for c in range(1, 8):
            ws.cell(row, c).border = thin
            if c > 1:
                ws.cell(row, c).alignment = Alignment(horizontal="center")
        if p >= 80:
            ws.cell(row, 7).fill = STATUS_FILL["Done"]
        elif p >= 40:
            ws.cell(row, 7).fill = STATUS_FILL["Partial"]
        else:
            ws.cell(row, 7).fill = STATUS_FILL["Todo"]
        row += 1

    ws.cell(row, 1, "* Tasks exclude N/A from counts/%").font = Font(italic=True, size=9, color="FF666666")
    row += 2

    ws.cell(row, 1, "Module breakdown").font = Font(bold=True, size=12, color="FF1F4E79")
    row += 1

    headers2 = ["Section", "Module", "Tasks*", "Done", "Partial", "Todo", "Progress %"]
    for col, h in enumerate(headers2, 1):
        apply_header(ws.cell(row, col, h), header_fill, header_font, thin)
    row += 1

    for (section, module), items in modules.items():
        statuses = [st for _, st, _, _, _ in items if st != "N/A"]
        done = statuses.count("Done")
        partial = statuses.count("Partial")
        todo = statuses.count("Todo")
        p = pct(statuses)
        ws.cell(row, 1, section).fill = PatternFill("solid", fgColor=SECTION_COLORS[section])
        ws.cell(row, 2, module).fill = PatternFill("solid", fgColor=MODULE_HEADER_TINT[section])
        ws.cell(row, 2).font = Font(bold=True)
        ws.cell(row, 3, len(statuses))
        ws.cell(row, 4, done)
        ws.cell(row, 5, partial)
        ws.cell(row, 6, todo)
        ws.cell(row, 7, p / 100).number_format = "0%"
        for c in range(1, 8):
            ws.cell(row, c).border = thin
            if c >= 3:
                ws.cell(row, c).alignment = Alignment(horizontal="center")
        if p >= 80:
            ws.cell(row, 7).fill = STATUS_FILL["Done"]
        elif p >= 40:
            ws.cell(row, 7).fill = STATUS_FILL["Partial"]
        else:
            ws.cell(row, 7).fill = STATUS_FILL["Todo"]
        row += 1

    # Suggested next priorities for Phase 4 (current)
    row += 1
    ws.cell(row, 1, "Suggested next priorities (Phase 4 — now through Aug 28)").font = Font(
        bold=True, size=12, color="FF1F4E79"
    )
    row += 1
    priorities = [
        "1. Finish VL leftovers: reject email + next-tier approver notify",
        "2. Admin/HR APIs for manual opening balance + adjust (no legacy leave import)",
        "3. VL → SL conversion request/review + balance side effects (FE Phase 4)",
        "4. Other leave types (EL/ML/PL/PTB/LL) submit + admin requirements — Phase 2/3 not done without these",
        "5. Report API (confirmed leave_info only) + staging cookie/env verify with infra",
    ]
    for prio in priorities:
        ws.cell(row, 1, prio)
        ws.merge_cells(start_row=row, start_column=1, end_row=row, end_column=7)
        row += 1

    ws.column_dimensions["A"].width = 28
    ws.column_dimensions["B"].width = 42
    ws.column_dimensions["C"].width = 10
    ws.column_dimensions["D"].width = 10
    ws.column_dimensions["E"].width = 10
    ws.column_dimensions["F"].width = 10
    ws.column_dimensions["G"].width = 12

    # ========== Checklist ==========
    ws2 = wb.create_sheet("Checklist")
    ws2["A1"] = "Leave Forms Revamp — Module / Task Checklist (Backend)"
    ws2["A1"].font = title_font
    ws2.merge_cells("A1:H1")
    ws2["A2"] = (
        f"Updated {date.today().isoformat()} · Status: Done / Partial / Todo / N/A · "
        "Phase = schedule phase · Aug scope = yes/no for August development commitment"
    )
    ws2["A2"].font = Font(italic=True, color="FF666666", size=10)
    ws2.merge_cells("A2:H2")

    for col, h in enumerate(
        ["Section", "Module", "Task", "Status", "Module %", "Phase", "Aug scope", "Notes"], 1
    ):
        apply_header(ws2.cell(4, col, h), header_fill, header_font, thin)

    mod_pct = {
        key: pct([st for _, st, _, _, _ in items if st != "N/A"]) for key, items in modules.items()
    }

    r = 5
    for section, module, task, status, notes, phase, august_dev in ROWS:
        ws2.cell(r, 1, section).fill = PatternFill("solid", fgColor=SECTION_COLORS[section])
        ws2.cell(r, 1).font = Font(bold=True, size=10)
        ws2.cell(r, 2, module).fill = PatternFill("solid", fgColor=MODULE_HEADER_TINT[section])
        ws2.cell(r, 2).font = Font(bold=True, size=10)
        ws2.cell(r, 3, task)
        status_cell = ws2.cell(r, 4, status)
        status_cell.fill = STATUS_FILL[status]
        status_cell.font = STATUS_FONT[status]
        status_cell.alignment = Alignment(horizontal="center")
        p = mod_pct[(section, module)]
        pct_cell = ws2.cell(r, 5, p / 100)
        pct_cell.number_format = "0%"
        pct_cell.alignment = Alignment(horizontal="center")
        if p >= 80:
            pct_cell.fill = STATUS_FILL["Done"]
        elif p >= 40:
            pct_cell.fill = STATUS_FILL["Partial"]
        else:
            pct_cell.fill = STATUS_FILL["Todo"]
        phase_label = {1: "1", 2: "2", 3: "3", 4: "4", 5: "5", "post": "Post-Aug", None: "—"}.get(
            phase, str(phase)
        )
        ws2.cell(r, 6, phase_label).alignment = Alignment(horizontal="center")
        ws2.cell(r, 7, "Yes" if august_dev else "No").alignment = Alignment(horizontal="center")
        if not august_dev:
            ws2.cell(r, 7).fill = STATUS_FILL["N/A"]
        ws2.cell(r, 8, notes).font = Font(color="FF666666", size=9)
        for c in range(1, 9):
            ws2.cell(r, c).border = thin
            ws2.cell(r, c).alignment = Alignment(vertical="center", wrap_text=True)
        r += 1

    ws2.column_dimensions["A"].width = 24
    ws2.column_dimensions["B"].width = 36
    ws2.column_dimensions["C"].width = 58
    ws2.column_dimensions["D"].width = 12
    ws2.column_dimensions["E"].width = 12
    ws2.column_dimensions["F"].width = 10
    ws2.column_dimensions["G"].width = 10
    ws2.column_dimensions["H"].width = 40
    ws2.auto_filter.ref = f"A4:H{r - 1}"
    ws2.freeze_panes = "A5"

    # ========== By Module ==========
    ws3 = wb.create_sheet("By Module")
    ws3["A1"] = "Leave Forms Revamp — By Module (Backend)"
    ws3["A1"].font = title_font
    ws3.merge_cells("A1:D1")
    ws3["A2"] = (
        f"Overall (excl. N/A): {overall_pct}%  ·  Aug-scope: {aug_pct}%  ·  "
        f"Done {done_n}  ·  Partial {partial_n}  ·  Todo {todo_n}  ·  N/A {na_n}  ·  "
        f"Updated {date.today().isoformat()}"
    )
    ws3["A2"].font = Font(italic=True, color="FF666666", size=10)
    ws3.merge_cells("A2:D2")

    r = 4
    for (section, module), items in modules.items():
        statuses = [st for _, st, _, _, _ in items if st != "N/A"]
        p = pct(statuses)
        done = statuses.count("Done")
        ws3.cell(r, 1, f"{module}  —  {p}%  ({done}/{len(statuses)} active)")
        ws3.merge_cells(start_row=r, start_column=1, end_row=r, end_column=4)
        for c in range(1, 5):
            cell = ws3.cell(r, c)
            cell.fill = PatternFill("solid", fgColor=SECTION_COLORS[section])
            cell.border = thin
        ws3.cell(r, 1).font = Font(bold=True, size=12)
        ws3.row_dimensions[r].height = 22
        r += 1
        for col, h in enumerate(["#", "Task", "Status", "Aug scope"], 1):
            cell = ws3.cell(r, col, h)
            cell.fill = PatternFill("solid", fgColor=MODULE_HEADER_TINT[section])
            cell.font = Font(bold=True, size=9)
            cell.border = thin
        r += 1
        for i, (task, status, _notes, _phase, august_dev) in enumerate(items, 1):
            ws3.cell(r, 1, i).alignment = Alignment(horizontal="center")
            ws3.cell(r, 2, task)
            st = ws3.cell(r, 3, status)
            st.fill = STATUS_FILL[status]
            st.font = STATUS_FONT[status]
            st.alignment = Alignment(horizontal="center")
            sc = ws3.cell(r, 4, "Yes" if august_dev else "No")
            sc.alignment = Alignment(horizontal="center")
            if not august_dev:
                sc.fill = STATUS_FILL["N/A"]
            for c in range(1, 5):
                ws3.cell(r, c).border = thin
            r += 1
        r += 1

    ws3.column_dimensions["A"].width = 6
    ws3.column_dimensions["B"].width = 70
    ws3.column_dimensions["C"].width = 12
    ws3.column_dimensions["D"].width = 12

    # ========== Legend ==========
    ws4 = wb.create_sheet("Legend")
    ws4["A1"] = "Legend, scoring & cutover policy"
    ws4["A1"].font = title_font

    ws4["A3"] = "Status"
    ws4["B3"] = "Meaning"
    ws4["C3"] = "Score toward %"
    for col in range(1, 4):
        apply_header(ws4.cell(3, col), header_fill, header_font, thin)

    legend_rows = [
        ("Done", "Implemented in the Laravel API", "1"),
        ("Partial", "Started / config present / incomplete", "0.5"),
        ("Todo", "Not started", "0"),
        ("N/A", "Out of scope by decision (excluded from %)", "—"),
    ]
    for i, (st, meaning, score) in enumerate(legend_rows, 4):
        ws4.cell(i, 1, st).fill = STATUS_FILL[st]
        ws4.cell(i, 1).font = STATUS_FONT[st]
        ws4.cell(i, 2, meaning)
        ws4.cell(i, 3, score)

    ws4["A9"] = "Cutover policy (confirmed)"
    ws4["A9"].font = Font(bold=True)
    ws4["A10"] = "1. Legacy leaveforms and revamp stay separate."
    ws4["A11"] = "2. Old leaves do NOT appear in the revamp (no leave history import)."
    ws4["A12"] = "3. Opening balances are entered manually from legacy numbers into revamp."
    ws4["A13"] = "4. Automated leave_confirmation bulk migration is N/A."

    ws4["A15"] = "Schedule alignment"
    ws4["A15"].font = Font(bold=True)
    ws4["A16"] = "Backend started first week of July (2026-07-01), ahead of FE Phase 1 start (2026-07-21)."
    ws4["A17"] = "Phases 2–3 = Partial (VL only). Other leave types (EL/ML/PL/PTB/LL) still todo."
    ws4["A18"] = "Phase 4 (2026-07-22 → 2026-08-28) covers conversion, manual balances, reports, and non-VL filing as needed."
    ws4["A19"] = "Testing & Documentation start on/after 2026-09-01 (not August commitment)."
    ws4["A20"] = "FE ↔ BE integration is parallel / TBD."

    ws4["A22"] = "Sources"
    ws4["A22"].font = Font(bold=True)
    ws4["A23"] = "docs/SCHEMA.md, BALANCE_FLOW.md, APPROVAL_RULES.md, LEGACY_NOTES.md"
    ws4["A24"] = "app/Services/Leave/*, routes/api.php, app/Mail/Leave/* · git commits 2026-07-07 / 07-17 / 07-21"
    ws4["A25"] = "Frontend schedule reference: Tentative Schedule — Frontend development (to end of August 2026)"

    ws4.column_dimensions["A"].width = 28
    ws4.column_dimensions["B"].width = 55
    ws4.column_dimensions["C"].width = 18

    base = r"C:\xampp\htdocs\leaveforms-api\docs\Leave_Forms_Revamp_Progress_Checklist"
    out = f"{base}.xlsx"
    for suffix in ["", "_v2", "_v3", "_v4"]:
        candidate = f"{base}{suffix}.xlsx" if suffix else f"{base}.xlsx"
        try:
            wb.save(candidate)
            out = candidate
            if suffix:
                print(f"Earlier file(s) locked (close Excel if open). Saved as {candidate}")
            break
        except PermissionError:
            continue
    else:
        raise PermissionError("Could not save Excel — close open checklist workbooks and retry.")
    print(f"Saved: {out}")
    print(f"Overall (excl N/A): {overall_pct}%  Done={done_n} Partial={partial_n} Todo={todo_n} N/A={na_n}")
    print(f"Aug-scope: {aug_pct}%  ({len(aug_statuses)} tasks)")
    print("Section %:")
    for section in sections:
        statuses = active_statuses([r for r in ROWS if r[0] == section])
        print(f"  {section}: {pct(statuses)}%")


if __name__ == "__main__":
    main()
