<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    /**
     * Display the list of employees with search and pagination.
     */
    public function index(Request $request): Response
    {
        $query = Employee::query()
            ->withCount('attendanceLogs')
            ->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('employee_code', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->input('status') === 'active');
        }

        $employees = $query->paginate(25)->through(fn (Employee $employee) => [
            'id' => $employee->id,
            'device_uid' => $employee->device_uid,
            'name' => $employee->name,
            'employee_code' => $employee->employee_code,
            'department' => $employee->department,
            'is_active' => $employee->is_active,
            'is_cloud_synced' => $employee->isCloudSynced(),
            'attendance_logs_count' => $employee->attendance_logs_count,
            'created_at' => $employee->created_at->toISOString(),
        ]);

        return Inertia::render('employees/Index', [
            'employees' => $employees,
            'filters' => $request->only(['search', 'status']),
        ]);
    }
}
