<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\DepartmentTarget;
use App\Models\AdminActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TargetCollectionController extends Controller
{
    public function getDepartments()
    {
        $departments = Department::where('is_active', true)
                                ->with(['targets' => function($q) {
                                    $q->where('year', date('Y'));
                                }])
                                ->orderBy('name')
                                ->get();

        return response()->json($departments);
    }

    public function storeDepartment(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:departments,code',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        $department = Department::create($validated);
        
        AdminActivity::log(
            auth()->id(),
            'create',
            'target_collection',
            "Created department: {$department->name}",
            null,
            $department->toArray()
        );

        return response()->json($department, 201);
    }

    public function updateDepartment(Request $request, Department $department)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:departments,code,' . $department->id,
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        $oldValues = $department->toArray();
        $department->update($validated);
        
        AdminActivity::log(
            auth()->id(),
            'update',
            'target_collection',
            "Updated department: {$department->name}",
            $oldValues,
            $department->toArray()
        );

        return response()->json($department);
    }

    public function destroyDepartment(Department $department)
    {
        $departmentName = $department->name;
        $department->delete();
        
        AdminActivity::log(
            auth()->id(),
            'delete',
            'target_collection',
            "Deleted department: {$departmentName}",
            $department->toArray(),
            null
        );

        return response()->json(null, 204);
    }

    public function getTargets(Request $request)
    {
        $validated = $request->validate([
            'year' => 'nullable|integer|min:2020|max:2030',
        ]);

        $year = $validated['year'] ?? date('Y');

        $targets = DepartmentTarget::with('department')
                                  ->where('year', $year)
                                  ->orderBy('annual_target', 'desc')
                                  ->get();

        return response()->json([
            'year' => $year,
            'targets' => $targets,
        ]);
    }

    public function storeTarget(Request $request)
    {
        $validated = $request->validate([
            'department_id' => 'required|exists:departments,id',
            'annual_target' => 'required|numeric|min:0',
            'year' => 'required|integer|min:2020|max:2030',
            'monthly_targets' => 'nullable|array',
            'monthly_targets.*' => 'numeric|min:0',
        ]);

        $existingTarget = DepartmentTarget::where('department_id', $validated['department_id'])
                                         ->where('year', $validated['year'])
                                         ->first();

        if ($existingTarget) {
            return response()->json(['message' => 'Target already exists for this department and year'], 422);
        }

        $target = DepartmentTarget::create($validated);
        
        AdminActivity::log(
            auth()->id(),
            'create',
            'target_collection',
            "Created target for department: {$target->department->name}",
            null,
            $target->toArray()
        );

        return response()->json($target->load('department'), 201);
    }

    public function updateTarget(Request $request, DepartmentTarget $target)
    {
        $validated = $request->validate([
            'annual_target' => 'required|numeric|min:0',
            'monthly_targets' => 'nullable|array',
            'monthly_targets.*' => 'numeric|min:0',
        ]);

        $oldValues = $target->toArray();
        $target->update($validated);
        
        AdminActivity::log(
            auth()->id(),
            'update',
            'target_collection',
            "Updated target for department: {$target->department->name}",
            $oldValues,
            $target->toArray()
        );

        return response()->json($target->load('department'));
    }

    public function updateMonthlyCollection(Request $request, DepartmentTarget $target)
    {
        $validated = $request->validate([
            'month' => 'required|in:january,february,march,april,may,june,july,august,september,october,november,december',
            'amount' => 'required|numeric|min:0',
        ]);

        $oldValues = [
            'month' => $validated['month'],
            'old_amount' => $target->getMonthlyCollection($validated['month']),
        ];

        $target->setMonthlyCollection($validated['month'], $validated['amount']);
        $target->save();

        AdminActivity::log(
            auth()->id(),
            'update',
            'target_collection',
            "Updated {$validated['month']} collection for {$target->department->name}",
            $oldValues,
            [
                'month' => $validated['month'],
                'new_amount' => $validated['amount'],
            ]
        );

        return response()->json($target->load('department'));
    }

    public function getReport(Request $request)
    {
        $validated = $request->validate([
            'year' => 'nullable|integer|min:2020|max:2030',
        ]);

        $year = $validated['year'] ?? date('Y');

        $targets = DepartmentTarget::with('department')
                                  ->where('year', $year)
                                  ->get();

        $report = $targets->map(function($target) {
            return [
                'department' => $target->department,
                'annual_target' => $target->annual_target,
                'total_collection' => $target->total_collection,
                'progress_percentage' => round($target->progress_percentage, 2),
                'monthly_collections' => [
                    'january' => $target->january_collection,
                    'february' => $target->february_collection,
                    'march' => $target->march_collection,
                    'april' => $target->april_collection,
                    'may' => $target->may_collection,
                    'june' => $target->june_collection,
                    'july' => $target->july_collection,
                    'august' => $target->august_collection,
                    'september' => $target->september_collection,
                    'october' => $target->october_collection,
                    'november' => $target->november_collection,
                    'december' => $target->december_collection,
                ],
            ];
        });

        $totalTarget = $targets->sum('annual_target');
        $totalCollection = $targets->sum('total_collection');
        $overallProgress = $totalTarget > 0 ? round(($totalCollection / $totalTarget) * 100, 2) : 0;

        return response()->json([
            'year' => $year,
            'departments' => $report,
            'summary' => [
                'total_target' => $totalTarget,
                'total_collection' => $totalCollection,
                'overall_progress' => $overallProgress,
                'departments_count' => $targets->count(),
            ],
        ]);
    }

    public function getMonthlyReport(Request $request)
    {
        $validated = $request->validate([
            'year' => 'nullable|integer|min:2020|max:2030',
            'month' => 'required|in:january,february,march,april,may,june,july,august,september,october,november,december',
        ]);

        $year = $validated['year'] ?? date('Y');
        $month = $validated['month'];

        $targets = DepartmentTarget::with('department')
                                  ->where('year', $year)
                                  ->get();

        $monthlyReport = $targets->map(function($target) use ($month) {
            return [
                'department' => $target->department,
                'monthly_target' => $target->monthly_targets[$month] ?? ($target->annual_target / 12),
                'monthly_collection' => $target->getMonthlyCollection($month),
                'monthly_progress' => $this->calculateMonthlyProgress($target, $month),
            ];
        });

        $totalMonthlyTarget = $monthlyReport->sum('monthly_target');
        $totalMonthlyCollection = $monthlyReport->sum('monthly_collection');
        $monthlyOverallProgress = $totalMonthlyTarget > 0 ? round(($totalMonthlyCollection / $totalMonthlyTarget) * 100, 2) : 0;

        return response()->json([
            'year' => $year,
            'month' => $month,
            'departments' => $monthlyReport,
            'summary' => [
                'total_monthly_target' => $totalMonthlyTarget,
                'total_monthly_collection' => $totalMonthlyCollection,
                'monthly_overall_progress' => $monthlyOverallProgress,
            ],
        ]);
    }

    private function calculateMonthlyProgress($target, $month)
    {
        $monthlyTarget = $target->monthly_targets[$month] ?? ($target->annual_target / 12);
        $monthlyCollection = $target->getMonthlyCollection($month);
        
        return $monthlyTarget > 0 ? round(($monthlyCollection / $monthlyTarget) * 100, 2) : 0;
    }
}
