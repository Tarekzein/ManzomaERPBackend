<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Requests\HRRequest;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Services\LeaveService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class LeaveController extends Controller
{
    public function __construct(private LeaveService $leave) {}

    public function index(Request $r)
    {
        return ApiResponse::success($this->leave->list($r->user()));
    }

    public function mine(Request $r)
    {
        return ApiResponse::success($this->leave->mine($r->user()));
    }

    public function store(HRRequest $r)
    {
        return ApiResponse::success($this->leave->request($r->user(), $r->validated()), 'Leave requested', status: 201);
    }

    public function review(HRRequest $r, LeaveRequest $leaveRequest)
    {
        return ApiResponse::success($this->leave->review($r->user(), $leaveRequest, $r->validated()), 'Leave request reviewed');
    }
}
