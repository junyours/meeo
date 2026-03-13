<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\DepartmentTarget;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DepartmentCollectionController extends Controller
{
    /**
     * Get all departments with their targets and collections
     */
    public function index(Request $request)
    {
        $request->validate([
            'start_year' => 'required|integer|min:2020|max:2030',
            'end_year' => 'required|integer|min:2020|max:2030',
        ]);

        $startYear = $request->input('start_year', date('Y'));
        $endYear = $request->input('end_year', date('Y'));
        
        $departments = Department::with(['targets' => function($query) use ($startYear, $endYear) {
            $query->whereBetween('year', [$startYear, $endYear]);
        }])
        ->where('is_active', true)
        ->orderBy('name')
        ->get();

        $departmentData = [];
        $totalTarget = 0;
        $totalCollection = 0;

        // Create data for each year in the range
        foreach ($departments as $department) {
            for ($year = $startYear; $year <= $endYear; $year++) {
                // Get target for this specific year, or create empty target data
                $target = $department->targets->where('year', $year)->first();
                
                if (!$target) {
                    // Create empty target data for departments without targets
                    $target = new DepartmentTarget([
                        'department_id' => $department->id,
                        'year' => $year,
                        'annual_target' => 0,
                        'monthly_targets' => [],
                        'january_collection' => 0,
                        'february_collection' => 0,
                        'march_collection' => 0,
                        'april_collection' => 0,
                        'may_collection' => 0,
                        'june_collection' => 0,
                        'july_collection' => 0,
                        'august_collection' => 0,
                        'september_collection' => 0,
                        'october_collection' => 0,
                        'november_collection' => 0,
                        'december_collection' => 0,
                    ]);
                }
                
                $targetAmount = $target ? $target->annual_target : 0;
                $collectionAmount = $target ? $target->total_collection : 0;
                $progressPercentage = $targetAmount > 0 ? ($collectionAmount / $targetAmount) * 100 : 0;

                $totalTarget += $targetAmount;
                $totalCollection += $collectionAmount;

                $departmentData[] = [
                    'id' => $department->id,
                    'name' => $department->name,
                    'code' => $department->code,
                    'description' => $department->description,
                    'year' => $year, // Add year to distinguish between years
                    'target' => [
                        'annual_target' => (float) $targetAmount,
                        'monthly_targets' => $target ? $target->monthly_targets : [],
                    ],
                    'collection' => [
                        'total_collection' => (float) $collectionAmount,
                        'monthly_collections' => $target ? [
                            'january' => (float) $target->january_collection,
                            'february' => (float) $target->february_collection,
                            'march' => (float) $target->march_collection,
                            'april' => (float) $target->april_collection,
                            'may' => (float) $target->may_collection,
                            'june' => (float) $target->june_collection,
                            'july' => (float) $target->july_collection,
                            'august' => (float) $target->august_collection,
                            'september' => (float) $target->september_collection,
                            'october' => (float) $target->october_collection,
                            'november' => (float) $target->november_collection,
                            'december' => (float) $target->december_collection,
                        ] : [],
                    ],
                    'performance' => [
                        'progress_percentage' => (float) $progressPercentage,
                        'target_met' => $collectionAmount >= $targetAmount,
                        'remaining' => max(0, $targetAmount - $collectionAmount),
                        'exceeded' => $collectionAmount > $targetAmount,
                        'excess_amount' => max(0, $collectionAmount - $targetAmount),
                    ]
                ];
            }
        }

        return response()->json([
            'start_year' => $startYear,
            'end_year' => $endYear,
            'departments' => $departmentData,
            'summary' => [
                'total_departments' => count($departments),
                'total_target' => (float) $totalTarget,
                'total_collection' => (float) $totalCollection,
                'overall_progress_percentage' => $totalTarget > 0 ? ($totalCollection / $totalTarget) * 100 : 0,
                'targets_met' => count(array_filter($departmentData, fn($dept) => $dept['performance']['target_met'])),
                'targets_exceeded' => count(array_filter($departmentData, fn($dept) => $dept['performance']['exceeded'])),
            ]
        ]);
    }

    /**
     * Create or update department target
     */
    public function storeTarget(Request $request)
    {
        $request->validate([
            'department_id' => 'required|exists:departments,id',
            'year' => 'required|integer|min:2020|max:2030',
            'annual_target' => 'required|numeric|min:0',
            'monthly_targets' => 'nullable|array',
            'monthly_targets.*' => 'numeric|min:0',
        ]);

        $department = Department::findOrFail($request->input('department_id'));
        $year = $request->input('year');

        $monthlyTargets = $request->input('monthly_targets', []);
        $months = ['january', 'february', 'march', 'april', 'may', 'june', 
                   'july', 'august', 'september', 'october', 'november', 'december'];
        
        // Ensure all 12 months are present
        foreach ($months as $index => $month) {
            if (!isset($monthlyTargets[$month])) {
                $monthlyTargets[$month] = 0;
            }
        }

        $target = DepartmentTarget::updateOrCreate(
            ['department_id' => $department->id, 'year' => $year],
            [
                'annual_target' => $request->input('annual_target'),
                'monthly_targets' => $monthlyTargets,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Department target saved successfully',
            'target' => $target->load('department'),
        ]);
    }

    /**
     * Update monthly collection for a department
     */
    public function updateMonthlyCollection(Request $request, $departmentId)
    {
        $request->validate([
            'year' => 'required|integer|min:2020|max:2030',
            'month' => 'required|string|in:january,february,march,april,may,june,july,august,september,october,november,december',
            'amount' => 'required|numeric|min:0',
        ]);

        $department = Department::findOrFail($departmentId);
        $year = $request->input('year');
        $month = $request->input('month');
        $amount = $request->input('amount');

        $target = DepartmentTarget::firstOrCreate(
            ['department_id' => $departmentId, 'year' => $year],
            [
                'annual_target' => 0,
                'monthly_targets' => [],
            ]
        );

        $target->setMonthlyCollection($month, $amount);
        $target->save();

        return response()->json([
            'success' => true,
            'message' => 'Monthly collection updated successfully',
            'target' => $target->fresh()->load('department'),
        ]);
    }

    /**
     * Update monthly target for a department
     */
    public function updateMonthlyTarget(Request $request, $departmentId)
    {
        $request->validate([
            'year' => 'required|integer|min:2020|max:2030',
            'month' => 'required|string|in:january,february,march,april,may,june,july,august,september,october,november,december',
            'target' => 'required|numeric|min:0',
        ]);

        $department = Department::findOrFail($departmentId);
        $year = $request->input('year');
        $month = $request->input('month');
        $targetAmount = $request->input('target');

        $target = DepartmentTarget::firstOrCreate(
            ['department_id' => $departmentId, 'year' => $year],
            [
                'annual_target' => 0,
                'monthly_targets' => [],
            ]
        );

        $monthlyTargets = $target->monthly_targets ?? [];
        $monthlyTargets[$month] = $targetAmount;
        
        // Recalculate annual target from monthly targets
        $newAnnualTarget = array_sum($monthlyTargets);
        
        $target->monthly_targets = $monthlyTargets;
        $target->annual_target = $newAnnualTarget;
        $target->save();

        return response()->json([
            'success' => true,
            'message' => 'Monthly target updated successfully',
            'target' => $target->fresh()->load('department'),
        ]);
    }

    /**
     * Create a new department
     */
    public function storeDepartment(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:departments,name',
            'code' => 'required|string|max:50|unique:departments,code',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $department = Department::create([
            'name' => $request->input('name'),
            'code' => $request->input('code'),
            'description' => $request->input('description'),
            'is_active' => $request->input('is_active', true),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Department created successfully',
            'department' => $department,
        ]);
    }

    /**
     * Get all departments
     */
    public function getDepartments()
    {
        $departments = Department::orderBy('name')->get();
        
        return response()->json([
            'success' => true,
            'departments' => $departments,
        ]);
    }

    /**
     * Get department details with performance data
     */
    public function show(Request $request, $departmentId)
    {
        $request->validate([
            'year' => 'required|integer|min:2020|max:2030',
        ]);

        $year = $request->input('year');
        
        $department = Department::with(['targets' => function($query) use ($year) {
            $query->where('year', $year);
        }])
        ->findOrFail($departmentId);

        $target = $department->targets->first();
        
        if (!$target) {
            return response()->json([
                'success' => false,
                'message' => 'No target found for this department in the specified year',
            ], 404);
        }

        $monthlyData = [];
        $months = ['january', 'february', 'march', 'april', 'may', 'june', 
                   'july', 'august', 'september', 'october', 'november', 'december'];
        
        foreach ($months as $month) {
            $collection = $target->getMonthlyCollection($month);
            $monthlyTarget = $target->monthly_targets[$month] ?? 0;
            
            $monthlyData[] = [
                'month' => ucfirst($month),
                'target' => (float) $monthlyTarget,
                'collection' => (float) $collection,
                'progress_percentage' => $monthlyTarget > 0 ? ($collection / $monthlyTarget) * 100 : 0,
                'target_met' => $collection >= $monthlyTarget,
                'remaining' => max(0, $monthlyTarget - $collection),
                'exceeded' => $collection > $monthlyTarget,
                'excess_amount' => max(0, $collection - $monthlyTarget),
            ];
        }

        return response()->json([
            'department' => [
                'id' => $department->id,
                'name' => $department->name,
                'code' => $department->code,
                'description' => $department->description,
            ],
            'target' => [
                'annual_target' => (float) $target->annual_target,
                'monthly_targets' => $target->monthly_targets,
                'total_collection' => (float) $target->total_collection,
                'progress_percentage' => (float) $target->progress_percentage,
                'target_met' => $target->total_collection >= $target->annual_target,
                'remaining' => max(0, $target->annual_target - $target->total_collection),
                'exceeded' => $target->total_collection > $target->annual_target,
                'excess_amount' => max(0, $target->total_collection - $target->annual_target),
            ],
            'monthly_breakdown' => $monthlyData,
        ]);
    }

    /**
     * Get dashboard summary
     */
    public function dashboard(Request $request)
    {
        $request->validate([
            'year' => 'required|integer|min:2020|max:2030',
        ]);

        $year = $request->input('year');
        
        $departments = Department::with(['targets' => function($query) use ($year) {
            $query->where('year', $year);
        }])
        ->where('is_active', true)
        ->get();

        $totalTarget = 0;
        $totalCollection = 0;
        $monthlyTotals = [];

        // Initialize monthly totals
        for ($i = 1; $i <= 12; $i++) {
            $monthlyTotals[$i] = 0;
        }

        foreach ($departments as $department) {
            $target = $department->targets->first();
            if ($target) {
                $totalTarget += $target->annual_target;
                $totalCollection += $target->total_collection;
                
                // Add to monthly totals
                $monthlyTotals[1] += $target->january_collection;
                $monthlyTotals[2] += $target->february_collection;
                $monthlyTotals[3] += $target->march_collection;
                $monthlyTotals[4] += $target->april_collection;
                $monthlyTotals[5] += $target->may_collection;
                $monthlyTotals[6] += $target->june_collection;
                $monthlyTotals[7] += $target->july_collection;
                $monthlyTotals[8] += $target->august_collection;
                $monthlyTotals[9] += $target->september_collection;
                $monthlyTotals[10] += $target->october_collection;
                $monthlyTotals[11] += $target->november_collection;
                $monthlyTotals[12] += $target->december_collection;
            }
        }

        $monthlyNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 
                       'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        $chartData = [];
        foreach ($monthlyTotals as $month => $total) {
            $chartData[] = [
                'month' => $monthlyNames[$month - 1],
                'collection' => (float) $total,
            ];
        }

        return response()->json([
            'year' => $year,
            'summary' => [
                'total_departments' => count($departments),
                'total_target' => (float) $totalTarget,
                'total_collection' => (float) $totalCollection,
                'overall_progress_percentage' => $totalTarget > 0 ? ($totalCollection / $totalTarget) * 100 : 0,
                'targets_met' => count(array_filter($departments->toArray(), fn($dept) => 
                    $dept['targets']->first() && 
                    $dept['targets']->first()->total_collection >= $dept['targets']->first()->annual_target
                )),
            ],
            'chart_data' => $chartData,
            'monthly_totals' => array_combine($monthlyNames, array_values($monthlyTotals)),
        ]);
    }

    /**
     * Delete department target
     */
    public function destroyTarget(Request $request, $targetId)
    {
        $target = DepartmentTarget::findOrFail($targetId);
        $target->delete();

        return response()->json([
            'success' => true,
            'message' => 'Department target deleted successfully',
        ]);
    }

    /**
     * Bulk update monthly collections
     */
    public function bulkUpdateCollections(Request $request)
    {
        $request->validate([
            'year' => 'required|integer|min:2020|max:2030',
            'collections' => 'required|array',
            'collections.*.department_id' => 'required|exists:departments,id',
            'collections.*.month' => 'required|string|in:january,february,march,april,may,june,july,august,september,october,november,december',
            'collections.*.amount' => 'required|numeric|min:0',
        ]);

        $year = $request->input('year');
        $collections = $request->input('collections');
        $updatedTargets = [];

        DB::transaction(function () use ($year, $collections, &$updatedTargets) {
            foreach ($collections as $collection) {
                $target = DepartmentTarget::where('department_id', $collection['department_id'])
                                           ->where('year', $year)
                                           ->first();
                
                if ($target) {
                    $target->setMonthlyCollection($collection['month'], $collection['amount']);
                    $target->save();
                    $updatedTargets[] = $target->fresh();
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Bulk collections updated successfully',
            'updated_targets' => $updatedTargets,
        ]);
    }
}
