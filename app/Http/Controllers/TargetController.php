<?php

namespace App\Http\Controllers;

use App\Models\Targets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TargetController extends Controller
{
     public function store(Request $request)
    {
        if (!auth()->user() || auth()->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'module' => 'required|string',
            'annual_target' => 'required|numeric|min:0',
        ]);

        $target = Targets::create([
            'user_id' => auth()->id(),
            'module' => $validated['module'],
            'annual_target' => $validated['annual_target'],
            'year' => now()->year,
        ]);

        return response()->json($target, 201);
    }

    // 📌 Update target
    public function update(Request $request, $id)
    {
        $target = Targets::findOrFail($id);

        if (!auth()->user() || auth()->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'annual_target' => 'required|numeric|min:0',
        ]);

        $target->update([
            'annual_target' => $validated['annual_target'],
        ]);

        return response()->json($target, 200);
    }

    // 📌 Report: get all targets + monthly collections
public function report(Request $request)
{
    $startYear = $request->query('start_year', now()->year);
    $endYear = $request->query('end_year', $startYear);

    $modules = ['Market', 'Slaughter', 'Motorpool', 'Wharf'];
    $allReports = [];

    for ($year = $startYear; $year <= $endYear; $year++) {
        $targets = Targets::where('year', $year)->get();
        $yearlyReport = [];

        foreach ($modules as $module) {
            $target = $targets->firstWhere('module', $module);
            $annualTarget = $target ? $target->annual_target : 0;
            $monthly = [];
            $totalCollection = 0;

            for ($m = 1; $m <= 12; $m++) {
                $table = match ($module) {
                    'Market' => 'payments',
                    'Slaughter' => 'slaughter_payment',
                    'Wharf' => 'wharf',
                    'Motorpool' => 'motorpool',
                };

                $amountColumn = match ($module) {
                    'Market' => 'amount',
                    'Slaughter' => 'total_amount',
                    'Wharf' => 'amount',
                    'Motorpool' => 'amount',
                };

                $query = DB::table($table)
                    ->whereYear('payment_date', $year)
                    ->whereMonth('payment_date', $m);

                $query = match ($module) {
                    'Market' => $query->where('status', 'remitted'),
                    'Slaughter' => $query->where('is_remitted', true),
                    'Wharf' => $query->where('status', 'remitted'),
                    'Motorpool' => $query->where('status', 'remitted'),
                };

                $sum = $query->sum($amountColumn);

                $monthly[$m] = $sum;
                $totalCollection += $sum;
            }

            $progress = $annualTarget > 0 ? round(($totalCollection / $annualTarget) * 100, 2) : 0;

            $yearlyReport[] = [
                'id' => $target?->id,
                'module' => $module,
                'annual_target' => $annualTarget,
                'monthly' => $monthly,
                'total_collection' => $totalCollection,
                'progress' => $progress,
            ];
        }

        $allReports[$year] = $yearlyReport;
    }

    return response()->json([
        'start_year' => $startYear,
        'end_year' => $endYear,
        'data' => $allReports
    ]);
}



}
