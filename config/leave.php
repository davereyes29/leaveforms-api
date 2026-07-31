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
    | Admin reviewer (VL → SL conversion, and admin leave requirements later)
    |--------------------------------------------------------------------------
    |
    | employee_list.id of the HR/admin account that reviews conversions.
    |
    */
    'admin_emp_id' => env('LEAVE_ADMIN_EMP_ID'),

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
        'max_kb' => (int) env('LEAVE_ATTACHMENT_MAX_KB', 5120),
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Email notifications
    |--------------------------------------------------------------------------
    */
    'notifications' => [
        'enabled' => env('LEAVE_NOTIFICATIONS_ENABLED', true),
    ],

];
