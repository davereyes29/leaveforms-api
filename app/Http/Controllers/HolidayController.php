<?php

namespace App\Http\Controllers;

use App\Http\Requests\Leave\IndexHolidayRequest;
use App\Http\Resources\HolidayResource;
use App\Models\Kdtph\KdtHoliday;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HolidayController extends Controller
{
    /**
     * GET /api/holidays — KDT company holidays for a date range or year.
     *
     * Query: ?from=YYYY-MM-DD&to=YYYY-MM-DD  or  ?year=2026
     * Always scoped to fldLocID = 1 (KDT).
     */
    public function index(IndexHolidayRequest $request): AnonymousResourceCollection
    {
        [$from, $to] = $request->resolvedRange();

        $holidays = KdtHoliday::query()
            ->where('fldLocID', KdtHoliday::KDT_LOCATION_ID)
            ->whereDate('fldDate', '>=', $from->toDateString())
            ->whereDate('fldDate', '<=', $to->toDateString())
            ->orderBy('fldDate')
            ->get();

        return HolidayResource::collection($holidays);
    }
}
