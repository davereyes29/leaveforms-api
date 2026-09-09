<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Portal authentication (shared with legacy leaveforms)
    |--------------------------------------------------------------------------
    |
    | The KDT portal sets a cookie (default: userID) containing a hash that maps
    | to kdtphdb.kdtlogin.fldUserHash. This API reads that cookie — it does not
    | issue its own login tokens in production.
    |
    | Local only: POST /api/dev/login returns fldUserHash for an emp_id so the
    | React app can set the userID cookie without KDTPortalLogin.
    |
    */
    'portal_cookie_name' => env('PORTAL_COOKIE_NAME', 'userID'),

    'portal_login_url' => env('PORTAL_LOGIN_URL', 'http://localhost/KDTPortalLogin'),

    /*
    |--------------------------------------------------------------------------
    | Approver fallback
    |--------------------------------------------------------------------------
    |
    | When no formspic row matches the filer's primary group, route to President.
    | Set LEAVE_PRESIDENT_EMP_ID to the employee_list id used in production.
    |
    */
    'president_emp_id' => env('LEAVE_PRESIDENT_EMP_ID'),

    /*
    |--------------------------------------------------------------------------
    | Admin reviewer (VL → SL / VL → EL conversion, Sick Leave, PTB, EL, LL, ML, PL, SPL)
    |--------------------------------------------------------------------------
    |
    | employee_list.id of the HR/admin account that reviews conversions
    | and admin-approved leave requests (SL, PTB, EL, LL, ML, PL, SPL).
    |
    */
    'admin_emp_id' => env('LEAVE_ADMIN_EMP_ID'),

    /*
    |--------------------------------------------------------------------------
    | Primary-group overrides (leaveforms only)
    |--------------------------------------------------------------------------
    |
    | kdtphdb_new.employee_list.group_id is read-only. Use this when an
    | employee's stored group is a dummy (e.g. Ship) but leave routing must
    | treat them as another group (e.g. Systems).
    |
    | Format: emp_id:group_id,emp_id:group_id
    | Example: 212:16
    |
    */
    'employee_group_overrides' => (static function (): array {
        $raw = trim((string) env('LEAVE_EMPLOYEE_GROUP_OVERRIDES', ''));

        if ($raw === '') {
            return [];
        }

        $map = [];

        foreach (preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $pair) {
            if (! str_contains($pair, ':')) {
                continue;
            }

            [$empId, $groupId] = array_map('trim', explode(':', $pair, 2));

            if (! ctype_digit($empId) || ! ctype_digit($groupId) || (int) $empId < 1 || (int) $groupId < 1) {
                continue;
            }

            $map[(int) $empId] = (int) $groupId;
        }

        return $map;
    })(),

    /*
    |--------------------------------------------------------------------------
    | React frontend (separate repo)
    |--------------------------------------------------------------------------
    |
    | Used for CORS so the browser can send the portal cookie with API requests.
    | Your coworker's dev server is typically http://localhost:5173 (Vite).
    |
    */
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    /*
    |--------------------------------------------------------------------------
    | Leave attachments
    |--------------------------------------------------------------------------
    */
    'attachments' => [
        'disk' => env('LEAVE_ATTACHMENT_DISK', 'local'),
        'max_kb' => (int) env('LEAVE_ATTACHMENT_MAX_KB', 10240),
        'max_files' => (int) env('LEAVE_ATTACHMENT_MAX_FILES', 5),
        'min_files' => 1,
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Email notifications
    |--------------------------------------------------------------------------
    */
    'notifications' => [
        'enabled' => env('LEAVE_NOTIFICATIONS_ENABLED', true),

        /*
        | Temporary banner on all leave emails so recipients know the message
        | is for demo / UAT only. Set LEAVE_MAIL_DEMO_LABEL=false to remove.
        */
        'demo_label' => env('LEAVE_MAIL_DEMO_LABEL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin approval reminders
    |--------------------------------------------------------------------------
    |
    | Bulk is once per Asia/Manila calendar day and does not lock singles.
    | Single is once per (leave, approver) per Manila day and does not lock bulk.
    |
    */
    'reminders' => [
        'timezone' => 'Asia/Manila',
    ],

    /*
    |--------------------------------------------------------------------------
    | Employment status (admin balance table)
    |--------------------------------------------------------------------------
    |
    | kdtphdb_new.designation_list has no employment-type column. Map
    | emp_prof.fldDesig → designation_list (KDTP always id 29), falling
    | back to employee_list.designation when fldDesig is missing or unknown.
    | Regular is everyone whose resolved designation is not in the lists below.
    |
    */
    'employment' => [
        'probationary_designation_ids' => [8, 20, 54],
        'contractual_designation_ids' => [9, 49, 51, 53],
    ],

    /*
    |--------------------------------------------------------------------------
    | Annual VL / SL entitlement (admin distribution)
    |--------------------------------------------------------------------------
    |
    | Tenure is complete years from employee_list.date_hired to 1 January of
    | the grant year (Asia/Manila). SL is 15 under the threshold, 18 at or over.
    |
    */
    'annual_entitlement' => [
        'tenure_years' => 3,
        'vl' => '15.00',
        'sl_under' => '15.00',
        'sl_at_or_over' => '18.00',
    ],

    /*
    |--------------------------------------------------------------------------
    | Newly regularized entitlement (admin distribution)
    |--------------------------------------------------------------------------
    |
    | Mid-year catch-up for employees who missed the Jan 1 annual grant.
    | Tenure is measured as of today (Asia/Manila). Regularization date is
    | date_hired + 6 months. VL/SL = remaining inclusive calendar months
    | through December × rate. PTB is a flat one-time grant. EL is granted
    | at hiring via POST /admin/balances, not on this screen.
    |
    */
    'regularization_entitlement' => [
        'min_months' => 6,
        'max_months' => 12,
        'monthly_rate' => '1.25',
        'ptb' => '180.00',
    ],

    /*
    |--------------------------------------------------------------------------
    | Contractual entitlement (admin distribution)
    |--------------------------------------------------------------------------
    |
    | Admin types VL and/or SL amounts per contractual employee (positive,
    | at most max_per_type). Ledger remarks are a fixed server string.
    |
    */
    'contractual_entitlement' => [
        'max_per_type' => '15.00',
        'remarks' => 'Contractual leave credits',
    ],

    /*
    |--------------------------------------------------------------------------
    | Vacation Leave (VL) advance notice
    |--------------------------------------------------------------------------
    |
    | Self-file only. File Leave for Members (on-behalf) skips this gate.
    | Duration below notice_threshold_days is not gated (same-day / late VL).
    | Exactly notice_threshold_days → start ≥ today + exact_notice_workdays
    | working days (weekends/holidays skipped). Above the threshold → start
    | ≥ today + long_notice_calendar_days calendar days (Asia/Manila).
    |
    | Quarterly VL cap is informational (never 422). Confirmed + pending_conversion
    | VL workdays overlapping the quarter count. Converted-to-SL/EL do not.
    | Projected == cap → employee warning only. Projected > cap → warning +
    | admin memo emails (impending on file, exceeded on approve, withdrawn
    | on cancel/reject/edit back under the cap).
    |
    */
    'vacation_leave' => [
        'notice_threshold_days' => '3.00',
        'exact_notice_workdays' => 3,
        'long_notice_calendar_days' => 30,
        'quarterly_cap_days' => '9.00',
    ],

    /*
    |--------------------------------------------------------------------------
    | Long Leave (LL)
    |--------------------------------------------------------------------------
    |
    | Unpaid. Duration is inclusive calendar days, bounded by calendar months.
    | Requires 1–5 supporting documents on submit. Dates cannot overlap other
    | active leave. Another pending LL also blocks a new file.
    |
    */
    'long_leave' => [
        'min_months' => 1,
        'max_months' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Maternity / Paternity Leave
    |--------------------------------------------------------------------------
    |
    | Credits are granted when the request is filed (not at annual /
    | regularization). ML is exactly 105 inclusive calendar days.
    | PL is exactly 7 workdays, at most 4 confirmed takes per employee,
    | and requires 1–5 Paternity Notification Form files.
    |
    */
    'parental_leave' => [
        'ml_days' => 105,
        'pl_workdays' => 7,
        'pl_max_confirmed' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Special Leave (SPL)
    |--------------------------------------------------------------------------
    |
    | Paid, event-specific, non-cumulative. No rolling credits, no carry,
    | no cash conversion. Duration is exact from the reason matrix.
    | Philippines-based / short-term overseas: working days.
    | Long-term overseas qualifying deaths: 21 calendar days + 2 travel
    | days (23 inclusive calendar days). Marriage stays working days.
    |
    */
    'special_leave' => [
        'ltd_death_calendar_days' => 23,
    ],

    /*
    |--------------------------------------------------------------------------
    | Leave monetization candidates (admin report)
    |--------------------------------------------------------------------------
    |
    | Excess VL / SL above the retained floors may be converted to cash.
    | Balances are as of min(today, Nov 30 of conversion year) Asia/Manila:
    | on or before the cut-off day use live available; after it, ledger
    | available_after as of end of that cut-off day.
    |
    */
    'monetization' => [
        'vl_retain' => '10.00',
        'sl_retain' => '30.00',
        'balance_cut_off_month' => 11,
        'balance_cut_off_day' => 30,
    ],

];
