<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiException;
use App\Http\Controllers\Controller;

/**
 * Stands in for the endpoints M3 implements, so the API's shape does not change later (AC-US6.2).
 * It reads no input and writes nothing.
 */
class NotImplementedController extends Controller
{
    private const MILESTONE = 'M3';

    public function __invoke(): never
    {
        throw new ApiException(501, 'not_implemented', 'Available in '.self::MILESTONE.'.', ['milestone' => self::MILESTONE]);
    }
}
