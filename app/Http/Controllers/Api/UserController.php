<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    /**
     * The seeded users, for the role switch (FR9, A10). Needs no X-Demo-User: the page calls this to offer the choice.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'users' => User::orderBy('id')->get(['id', 'name', 'role', 'tier']),
        ]);
    }
}
