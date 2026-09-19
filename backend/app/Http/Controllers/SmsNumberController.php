<?php

namespace App\Http\Controllers;

use App\Services\DynalinkService;
use App\Http\Controllers\Concerns\ResolvesActor;
use Illuminate\Http\Request;

class SmsNumberController extends Controller
{
    use ResolvesActor;
    public function __construct(protected DynalinkService $dynalink) {}

    /** GET /api/sms-numbers — numbers assigned to this user (from-number choices). */
    public function index(Request $request)
    {
        $a = $this->actor($request);
        return response()->json($this->dynalink->smsNumbers($this->dtoken(request()), $a['domain'], $a['user']));
    }
}
