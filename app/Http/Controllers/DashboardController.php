<?php

namespace App\Http\Controllers;

use App\Models\Pool;
use App\Pool\SnapshotService;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    /**
     * The dashboard for the first pool. The snapshot is built here and embedded in the page, so the first paint already
     * shows the right numbers and the script only takes over (DD14). No viewer is passed: the client chooses who is looking.
     */
    public function __invoke(SnapshotService $snapshots): View
    {
        $pool = Pool::query()->orderBy('id')->first();

        abort_if($pool === null, 404, 'No pool has been seeded yet. Run: php artisan db:seed');

        return view('dashboard', ['snapshot' => $snapshots->build($pool, null)]);
    }
}
