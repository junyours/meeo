<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Rented;
use App\Models\Stalls;
use App\Models\Application;
use App\Models\Notification;
use Illuminate\Http\Request;
use App\Models\VendorDetails;
use App\Models\MarketRegistration;
use App\Models\StallChangeRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ApplicationController extends Controller
{
    // Store multiple stall applications

public function store(Request $request)
{
    $vendorDetailsId = VendorDetails::where('user_id', auth()->id())->value('id');

    if (!$vendorDetailsId) {
        return response()->json([
            'message' => 'Vendor profile not found.'
        ], 404);
    }

    $pendingApp = Application::where('vendor_id', $vendorDetailsId)
        ->where('status', 'pending')
        ->first();

    if ($pendingApp) {
        return response()->json([
            'message' => 'You already have a pending application. Please wait for approval.'
        ], 403);
    }

    $validated = $request->validate([
        'business_name'     => 'required|string|max:255',
        'section_id'        => 'required|exists:section,id',
        'stall_ids'         => 'required|array|min:1',
        'stall_ids.*'       => 'exists:stall,id',
        'payment_type'      => 'required|in:daily,monthly',
        'letter_of_intent'  => 'nullable|file|mimes:jpeg,png,jpg,gif|max:5120',
    ]);

    $data = $validated;

    if ($request->hasFile('letter_of_intent')) {
        $file = $request->file('letter_of_intent');
        $filename = time() . '_' . $file->getClientOriginalName();

        // 1) Save to storage/app/public/letter_of_intent
        $path = $file->storeAs('letter_of_intent', $filename, 'public'); 
        $data['letter_of_intent'] = $path;

        // 2) Also copy to public/storage/letter_of_intent
        $publicStorageDir = public_path('storage/letter_of_intent');
        if (!file_exists($publicStorageDir)) {
            mkdir($publicStorageDir, 0755, true);   // create folder if missing
        }

        copy(
            storage_path('app/public/' . $path),           // from storage/app/public/letter_of_intent/...
            $publicStorageDir . '/' . $filename            // to public/storage/letter_of_intent/...
        );
    }

    $application = Application::create([
        'vendor_id'        => $vendorDetailsId,
        'business_name'    => $data['business_name'],
        'section_id'       => $data['section_id'],
        'stall_ids'        => $data['stall_ids'],
        'payment_type'     => $data['payment_type'],
        'status'           => 'pending',
        'letter_of_intent' => $data['letter_of_intent'] ?? null,
    ]);

    Notification::create([
        'message' => 'A new Application Form has been submitted and is pending approval.',
        'title'   => 'New Application Form',
        'is_read' => 0,
    ]);

    return response()->json([
        'message'      => 'letter_of_intent submitted!',
        'application'  => $application
    ]);
}


    // Vendor get my applications
public function myApplications() 
{
    Log::info('✅ myApplications() called by user ID: ' . auth()->id());

    // ✅ Get vendor details
    $vendorDetails = VendorDetails::where('user_id', auth()->id())->first();
    Log::info('📌 Vendor Details:', ['vendor' => $vendorDetails]);

    if (!$vendorDetails) {
        Log::warning('❌ Vendor profile NOT FOUND');

        return response()->json([
            'approved' => [],
            'pending' => [],
            'vendor_status' => 'not_submitted',
            'message' => 'Vendor profile not found.',
            'is_blocklisted' => false,
            'blocklist_message' => null
        ]);
    }

    // ✅ Fetch vendor applications
    $apps = Application::with([
        'section', 
        'vendor', 
        'stallChangeRequests', 
        'marketRegistration'
    ])
    ->where('vendor_id', $vendorDetails->id)
    ->get();

    Log::info('📦 Vendor Applications:', [
        'count' => $apps->count(),
        'application_ids' => $apps->pluck('id')
    ]);

    $approved = $apps->where('status', 'approved');
    $pending  = $apps->where('status', 'pending');

    // ✅ Check pending stall change
    $hasPendingStallChange = StallChangeRequest::where('vendor_id', $vendorDetails->id)
        ->where('status', 'pending')
        ->exists();

    Log::info('🔄 Has Pending Stall Change:', [
        'has_pending_stall_change' => $hasPendingStallChange
    ]);

    // =====================================================
    // ✅ BLOCKLIST CHECK — RAW DATABASE (BYPASS ELOQUENT)
    // =====================================================

 $applicationIds = $apps->pluck('id')->toArray();

$raw = DB::select("SELECT id, status, missed_days FROM rented WHERE application_id = 1");

Log::warning('🧾 DIRECT SQL RENTED CHECK (application_id = 1):', json_decode(json_encode($raw), true));

    Log::info('🆔 Vendor Application IDs:', $applicationIds);
Log::warning('✅ LARAVEL DATABASE NAME:', [
    DB::connection()->getDatabaseName()
]);

    $rentedRecords = DB::table('rented')
        ->whereIn('application_id', $applicationIds)
        ->select('id', 'application_id', 'status', 'missed_days')
        ->get();

    Log::info('🧪 RAW DATABASE RENTED RECORDS:', $rentedRecords->toArray());

    $isBlocklisted = false;
    $blocklistMessage = null;

    foreach ($rentedRecords as $rented) {

        Log::info('🔍 RAW CHECK:', [
            'rented_id' => $rented->id,
            'application_id' => $rented->application_id,
            'status' => $rented->status,
            'missed_days' => $rented->missed_days
        ]);

        // ✅ EXACT SAME CONDITION AS ADMIN
        if (
            strtolower(trim($rented->status)) === 'unoccupied' &&
            (int)$rented->missed_days > 20
        ) {
            $isBlocklisted = true;
            $blocklistMessage = 'You are currently blocklisted due to unpaid stalls. Please settle your dues before submitting another application.';

            Log::warning('🚨 RAW BLOCKLIST MATCH FOUND!', [
                'rented_id' => $rented->id,
                'missed_days' => $rented->missed_days
            ]);

            break;
        }
    }

    Log::warning('📢 FINAL RAW BLOCKLIST RESULT:', [
        'is_blocklisted' => $isBlocklisted
    ]);

    // ===============================
    // ✅ GROUPING FUNCTION
    // ===============================

    $groupByBusiness = function ($collection) {
        return $collection->groupBy('business_name')->map(function ($items, $bizName) {
            $first = $items->first();

            $stalls = $items->flatMap(fn($app) => $app->stalls_with_rates)->values();

            $marketReg = $first->marketRegistration;
            $hasRegistration = $marketReg ? true : false;
            $registrationIssuedAt = $marketReg?->date_issued;

            return [
                'id' => $first->id,
                'created_at' => $first->created_at,
                'vendor_id' => $first->vendor_id,
                'business_name' => $bizName,
                'section_id' => $first->section?->id,
                'section' => $first->section?->name,
                'status' => $first->status,
                'payment_type' => $first->payment_type,
                'stalls' => $stalls,
                'has_pending_change' => $first->stallChangeRequests()
                    ->where('status', 'pending')
                    ->exists(),
                'has_registration' => $hasRegistration,
                'registration_issued_at' => $registrationIssuedAt,
                'letter_of_intent' => $first->letter_of_intent 
                    ? asset('storage/' . $first->letter_of_intent) 
                    : null,
            ];
        })->values();
    };

    // ✅ FINAL RESPONSE
    return response()->json([
        'approved' => $groupByBusiness($approved),
        'pending' => $groupByBusiness($pending),
        'vendor_status' => strtolower($vendorDetails->Status),
        'has_pending_stall_change' => $hasPendingStallChange,
        'is_blocklisted' => $isBlocklisted,
        'blocklist_message' => $blocklistMessage
    ]);
}













    // Admin get all applications
public function index(Request $request)
{
    $query = Application::with(['vendor', 'section']);

    if ($request->has('start_date') && $request->has('end_date')) {
        $query->whereDate('updated_at', '>=', $request->start_date)
              ->whereDate('updated_at', '<=', $request->end_date);
    }

    $applications = $query->get();

    return response()->json([
        'applications' => $applications->map(function ($app) {
            return [
                'id' => $app->id,
                'business_name' => $app->business_name,
                'payment_type' => $app->payment_type,
                'status' => $app->status,
                'vendor' => $app->vendor,
                'section' => $app->section,
                'stall_details' => $app->stalls_with_rates,
                'letter_of_intent' => $app->letter_of_intent 
                    ? asset('storage/' . $app->letter_of_intent) 
                    : null,
                'date_approved' => $app->updated_at ? $app->updated_at->format('Y-m-d') : null,
            ];
        })
    ]);
}






public function approved(Request $request)
{
    $query = Application::with(['vendor', 'section', 'marketRegistration'])
        ->where('status', 'approved');

    // ✅ Filter by date range (if provided)
    if ($request->has(['start_date', 'end_date'])) {
        $start = $request->start_date;
        $end = $request->end_date;

        $query->whereHas('marketRegistration', function ($q) use ($start, $end) {
            $q->whereBetween('date_issued', [$start, $end]);
        });
    }

    $applications = $query->get();

    return response()->json(
        $applications->map(function ($app) {
            return [
                'id' => $app->id,
                'business_name' => $app->business_name,
                'status' => $app->status,
                'vendor' => $app->vendor,
                'section' => $app->section,
                'stall_details' => $app->stalls_with_rates,
                'market_registration' => $app->marketRegistration
                    ? [
                        'id' => $app->marketRegistration->id,
                        'date_issued' => $app->marketRegistration->date_issued,
                        'expiry_date' => $app->marketRegistration->expiry_date,
                    ]
                    : null,
            ];
        })
    );
}



public function updateStatus(Request $request, $id)
{
    $validated = $request->validate([
        'status' => 'required|in:pending,approved,rejected',
    ]);

    $application = Application::with('vendor')->findOrFail($id);
    $application->status = $validated['status'];
    $application->save();

    $vendor = $application->vendor;

    // Create a notification for the vendor
    $message = $application->status === 'approved'
        ? 'Your Application Form has been approved by the admin. Please wait for the admin to provide the market registration.'
        : 'Your Application Form has been rejected. You can submit a new Application Form again.';

    Notification::create([
        'vendor_id' => $vendor->id,
        'message'   => $message,
        'title'     => 'Application Form Update',
        'is_read'   => 0, // unread by default
    ]);

    return response()->json([
        'message' => 'Application status updated and notification created successfully.',
        'application' => $application,
    ]);
}


public function requestStallChange(Request $request, $id)
{
    $vendorDetailsId = VendorDetails::where('user_id', auth()->id())->value('id');

    if (!$vendorDetailsId) {
        return response()->json(['message' => 'Vendor profile not found.'], 404);
    }

    $application = Application::where('id', $id)
        ->where('vendor_id', $vendorDetailsId)
        ->first();

    if (!$application) {
        return response()->json(['message' => 'Application not found.'], 404);
    }

    $validated = $request->validate([
        'new_stall_ids'   => 'required|array|min:1',
        'new_stall_ids.*' => 'exists:stall,id',
    ]);

    // 🔹 Get old stall IDs from the current application
    $oldStallIds = $application->stall_ids; 
    // assuming your `applications` table has a `stall_ids` (json/array) column  

    $changeRequest = StallChangeRequest::create([
        'application_id' => $application->id,
        'vendor_id'      => $vendorDetailsId,
        'old_stall_ids'  => $oldStallIds,              // 🔹 Save old stall ids
        'new_stall_ids'  => $validated['new_stall_ids'], // 🔹 Save new stall ids
        'status'         => 'pending',
    ]);

       Notification::create([
       
        'message' => 'Theres An vendor that request an stall change and its been submitted.',
        'title' => 'Stall Change Request',
        'is_read' => 0,
    ]);


    return response()->json([
        'message' => 'Stall change request submitted, waiting for admin approval.',
        'request' => $changeRequest,
    ]);
}


public function listStallChangeRequests(Request $request)
{
    $startDate = $request->query('start_date');
    $endDate = $request->query('end_date');

    $query = StallChangeRequest::with(['application.vendor', 'application.section']);

    if ($startDate && $endDate) {
        $query->whereBetween('updated_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
    }

    // 🟢 Order by most recent first
    $requests = $query->orderBy('updated_at', 'desc')->get();

    return response()->json([
        'requests' => $requests->map(function ($req) {
            return [
                'id' => $req->id,
                'application_id' => $req->application_id,
                'vendor' => $req->application->vendor,
                'business_name' => $req->application->business_name,
                'section' => $req->application->section,
                'current_stalls' => $req->old_stall_ids,
                'new_stalls' => $req->new_stall_ids,
                'status' => $req->status,
                'updated_at' => $req->updated_at ? $req->updated_at->format('Y-m-d') : null,
            ];
        })
    ]);
}


public function updateStallChangeStatus(Request $request, $id)
{
    $validated = $request->validate([
        'status' => 'required|in:approved,rejected',
    ]);

    $changeRequest = StallChangeRequest::with('application.section', 'application.vendor')->findOrFail($id);
    $application = $changeRequest->application;
    $vendor = $application->vendor;

    if ($validated['status'] === 'approved') {
        $section = $application->section;

        $oldStallIds = $application->stall_ids ?? [];
        $newStallIds = $changeRequest->new_stall_ids ?? [];

        // ✅ Step 1: Vacate all old stalls
        if (!empty($oldStallIds)) {
            Stalls::whereIn('id', $oldStallIds)->update(['status' => 'vacant']);
        }

        // ✅ Step 2: Occupy only new stalls
        if (!empty($newStallIds)) {
            Stalls::whereIn('id', $newStallIds)->update(['status' => 'occupied']);
        }

        // ✅ Step 3: Update application with new stall IDs
        $application->stall_ids = $newStallIds;
        $application->save();

        // ✅ Step 4: Sync rented stalls
        Rented::where('application_id', $application->id)->delete();

        foreach ($newStallIds as $stallId) {
            $stall = Stalls::find($stallId);
            if (!$stall) continue;

            if ($section->rate_type === 'per_sqm') {
                $dailyRent = $stall->size * $section->rate;
                $monthlyRent = $dailyRent * 30;
            } else {
                $monthlyRent = $section->monthly_rate;
                $dailyRent = round($monthlyRent / 30, 2);
            }

            Rented::create([
                'application_id' => $application->id,
                'stall_id'       => $stall->id,
                'daily_rent'     => $dailyRent,
                'monthly_rent'   => $monthlyRent,
            ]);
        }

        // ✅ Create notification for vendor (approved)
        Notification::create([
            'vendor_id' => $vendor->id,
            'title' => 'Stall Change Request Approved',
                  'is_read' => false,
            'message' => "Good news! Your stall change request for section '{$section->name}' has been approved by the admin.",
        ]);
    } elseif ($validated['status'] === 'rejected') {
        // ✅ Create notification for vendor (rejected)
        Notification::create([
            'vendor_id' => $vendor->id,
            'title' => 'Stall Change Request Rejected',
                  'is_read' => false,
            'message' => "Your stall change request for section '{$changeRequest->application->section->name}' has been rejected by the admin.",
        ]);
    }

    // ✅ Save request status
    $changeRequest->status = $validated['status'];
    $changeRequest->save();

    return response()->json([
        'message' => "✅ Stall change request {$validated['status']} successfully.",
        'request' => $changeRequest
    ]);
}


}
