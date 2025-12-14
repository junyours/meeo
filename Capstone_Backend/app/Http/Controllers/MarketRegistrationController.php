<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Rented;
use App\Models\Stalls;
use App\Models\Payments;
use App\Models\Sections;
use App\Models\Application;
use App\Models\Notification;
use Illuminate\Http\Request;
use App\Models\VendorDetails;
use App\Models\InchargeCollector;
use App\Models\MarketRegistration;
use Illuminate\Support\Facades\DB;
use App\Models\MarketRegistrationRenewalRequest;

class MarketRegistrationController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'application_id' => 'required|exists:applications,id',
        ]);

        $application = Application::with(['vendor', 'section', 'stall'])
            ->findOrFail($request->application_id);

        if ($application->status !== 'approved') {
            return response()->json(['message' => 'Application is not approved.'], 400);
        }

        // Check if already issued
        if (MarketRegistration::where('application_id', $application->id)->exists()) {
            return response()->json(['message' => 'Market registration already issued.'], 400);
        }

        $registration = MarketRegistration::create([
            'application_id' => $application->id,
            'date_issued' => now()->toDateString(),
        ]);

        return response()->json([
            'message' => 'Market registration issued successfully.',
            'data' => [
                'date_issued' => $registration->date_issued,
                'fullname' => $application->vendor->fullname,
                'business_name' => $application->business_name,
                'section' => $application->section->name,
                'stall' => $application->stall->number,
            ],
        ]);
    }

public function renew($id, Request $request)
{
    $registration = MarketRegistration::findOrFail($id);

    // Check if request already exists
    $existingRequest = MarketRegistrationRenewalRequest::where('registration_id', $id)
                            ->where('status', 'pending')
                            ->first();

    if ($existingRequest) {
        return response()->json(['message' => 'Renewal request already submitted.'], 422);
    }

    // Create a renewal request
    $renewal = MarketRegistrationRenewalRequest::create([
        'registration_id' => $registration->id,
        'vendor_id' => $registration->application->vendor->id,
        'status' => 'pending',
    ]);

    // Send notification
  
   Notification::create([
        'message' => 'Theres An vendor that request an Renewal For the Market Registration and its been submitted.',
        'title' => 'Market Registration Renewal',
        'is_read' => 0,
    ]);

    return response()->json([
        'message' => 'Renewal request submitted. Awaiting admin approval.',
        'renewal' => $renewal
    ]);
}
public function issueRegistration(Request $request, $applicationId)
{
    $rentedRecords = DB::transaction(function () use ($applicationId, $request) {

        $application = Application::with(['vendor', 'section'])
            ->where('id', $applicationId)
            ->where('status', 'approved')
            ->first();

        if (!$application) {
            return [
                'message' => '❌ No approved application found for this ID.',
                'registration' => null,
                'rented_list' => []
            ];
        }

        $existingRegistration = MarketRegistration::where('application_id', $application->id)
            ->latest('date_issued')
            ->first();

        $now = Carbon::now()->startOfDay();
        $renewalWindowDays = 5; // allow renewal 5 days before expiry

        if ($existingRegistration) {
            $expiryDate = Carbon::parse($existingRegistration->expiry_date);

            if ($expiryDate->diffInDays($now, false) > $renewalWindowDays) {
                return [
                    'message' => "❌ Market registration can only be renewed within $renewalWindowDays days before expiry.",
                    'registration' => $existingRegistration,
                    'rented_list' => []
                ];
            }
        }

        // Compute new expiry date (1 year from issued date)
        $dateIssued = $now;
        $newExpiryDate = $dateIssued->copy()->addYear();

        // Leap year adjustment
        for ($y = $dateIssued->year; $y <= $newExpiryDate->year; $y++) {
            $isLeap = ($y % 4 === 0) && ($y % 100 !== 0 || $y % 400 === 0);
            if (!$isLeap) continue;

            $feb29 = Carbon::create($y, 2, 29)->startOfDay();
            if ($feb29->gt($dateIssued) && $feb29->lte($newExpiryDate)) {
                $newExpiryDate->addDay();
                break;
            }
        }

        $signatureData = $request->input('signature');

        $registration = MarketRegistration::create([
            'application_id' => $application->id,
            'date_issued'    => $dateIssued->toDateString(),
            'expiry_date'    => $newExpiryDate->toDateString(),
            'signature'      => $signatureData,
        ]);

        // 🔹 Create notification for the vendor
        Notification::create([
            'vendor_id' => $application->vendor->id,
            'title'     => 'Market Registration Issued',
            'message'   => "Your Market Registration for {$application->business_name} has been issued successfully. Valid until {$newExpiryDate->toFormattedDateString()}.",
            'is_read'   => 0,
        ]);

        $section  = $application->section;
        $stallIds = is_array($application->stall_ids) ? $application->stall_ids : json_decode($application->stall_ids, true);

        $rentedList = [];

        if ($stallIds && count($stallIds) > 0) {
            foreach ($stallIds as $stallId) {
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
                    'monthly_rent'   => $monthlyRent
                ]);

                $stall->update(['status' => 'occupied']);

                $rentedList[] = [
                    'stall_number' => $stall->stall_number,
                    'stall_size'   => $stall->size,
                    'section_name' => $section->name,
                    'daily_rent'   => $dailyRent,
                    'monthly_rent' => $monthlyRent
                ];
            }
        }

        return [
            'message'      => 'Market registration issued successfully',
            'registration' => $registration,
            'rented_list'  => $rentedList
        ];
    });

    return response()->json($rentedRecords);
}


public function viewRegistration($applicationId)
{
    $registration = MarketRegistration::with([
        'application.vendor',
        'application.section'
    ])
    ->where('application_id', $applicationId)
    ->latest('date_issued')
    ->first();

    if (!$registration) {
        return response()->json([
            'message' => '❌ No registration found for this application.'
        ], 404);
    }

    $application = $registration->application;
    $stalls = [];
    $stallIds = is_array($application->stall_ids)
        ? $application->stall_ids
        : json_decode($application->stall_ids, true);

    if ($stallIds && count($stallIds) > 0) {
        $stalls = Stalls::whereIn('id', $stallIds)
            ->pluck('stall_number')
            ->toArray();
    }

    return response()->json([
        'message' => '✅ Registration fetched successfully.',
        'registration' => [
            'id' => $registration->id,
            'date_issued' => $registration->date_issued,
            'expiry_date' => $registration->expiry_date,
            'signature' => $registration->signature,
        ],
        'application' => [
            'id' => $application->id,
            'business_name' => $application->business_name,
            'vendor' => $application->vendor,
            'section' => $application->section,
            'stalls' => $stalls,
        ],
    ]);
}


public function myMarketRegistration()
{
    $vendorDetailsId = VendorDetails::where('user_id', auth()->id())->value('id');

    if (!$vendorDetailsId) {
        return response()->json([
            'message' => 'Vendor profile not found.'
        ], 404);
    }

    // Get ALL applications that have market registration
    $applications = Application::with(['section', 'vendor', 'marketRegistration'])
        ->where('vendor_id', $vendorDetailsId)
        ->whereHas('marketRegistration')
        ->get();

    if ($applications->isEmpty()) {
        return response()->json([
            'message' => 'No market registration found for this vendor.'
        ], 404);
    }

    // Group applications by registration ID
    $grouped = $applications->groupBy('marketRegistration.id')->map(function ($apps) {
        $registration = $apps->first()->marketRegistration;
        $vendor       = $apps->first()->vendor;
        $section      = $apps->first()->section;
  $businessName = $apps->first()->business_name;
        // Use accessor to get all stall numbers
        $stalls = $apps->flatMap(fn($app) => $app->stalls_with_rates->pluck('stall_number'))->toArray();

        return [
            'registration' => $registration,
            'vendor'       => $vendor,
            'section'      => $section,
            'stalls'       => $stalls,
               'business_name'=> $businessName,
                 'signature'      => $registration->signature,
                      'renewal_requested' => $registration->renewal_requested, 
                 
        ];
    })->values();

    return response()->json([
        'registrations' => $grouped
    ]);
}

public function getRenewalRequests()
{
    $renewals = MarketRegistrationRenewalRequest::with(['registration.application.vendor', 'registration.application.section'])
        ->orderBy('updated_at', 'desc')
        ->get()
        ->map(function($req) {
            $registration = $req->registration;
            $application = $registration->application ?? null;
            $vendor = $req->vendor;

            return [
                'id' => $req->id,
                'business_name' => $application->business_name ?? 'N/A',
                'vendor' => [
                    'fullname' => $vendor->fullname ?? 'N/A',
                    'vendor_id' => $vendor->id ?? null,
                    'address' => $vendor->address ?? '',
                ],
                'registration' => [
                    'expiry_date' => $registration->expiry_date ?? null,
                    'date_issued' => $registration->date_issued ?? null,
                ],
                'status' => $req->status,
                'reason' => $req->reason,
                'updated_at' => $req->updated_at,
            ];
        });

    return response()->json(['renewals' => $renewals]);
}




/**
 * Approve or reject a renewal request and create a notification for the vendor
 */
public function handleRenewalAction(Request $request, $id, $action)
{
    $registrationRequest = MarketRegistrationRenewalRequest::with('registration.application.vendor')->findOrFail($id);

    if (!in_array($action, ['approve', 'reject'])) {
        return response()->json(['message' => 'Invalid action'], 400);
    }

    $vendor = $registrationRequest->vendor ?? $registrationRequest->registration->application->vendor;
    $notificationMessage = '';

    if ($action === 'approve') {
        $registrationRequest->registration->expiry_date = now()->addYear();
        $registrationRequest->registration->save();

        $registrationRequest->status = 'approved';
        $notificationMessage = "Your market registration renewal has been approved.";
    } else {
        $registrationRequest->status = 'rejected';
        $reason = $request->input('reason', 'No reason provided');
        $notificationMessage = "Your market registration renewal was rejected: $reason";
    }

    $registrationRequest->save();

    // Create notification for the vendor
    Notification::create([
        'vendor_id' => $vendor->id,
        'message' => $notificationMessage,
        'title' => $action === 'approve' ? 'Renewal Approved' : 'Renewal Rejected',
        'is_read' => false,
    ]);

    return response()->json(['message' => "Renewal request $action successfully"]);
}





    public function generatePDF($id)
    {
        $registration = MarketRegistration::findOrFail($id);

        // Limit PDF generation to 2
        if ($registration->pdf_generated_count >= 2) {
            return response()->json([
                'success' => false,
                'message' => 'You have already generated the PDF twice.'
            ], 403);
        }

        // Increment count
        $registration->increment('pdf_generated_count');

        return response()->json([
            'success' => true,
            'registration' => $registration,
        ]);
    }
    
 public function rentedList()
    {
        $rented = Rented::with(['vendor', 'stall'])->get();
        return response()->json($rented);
    }

    // Collect payment

public function collectPayment(Request $request)
{
    $validated = $request->validate([
        'rented_id'    => 'required|exists:rented,id',
        'payment_type' => 'required|in:daily,monthly',
        'amount'       => 'required|numeric|min:0',
        'payment_date' => 'required|date',
        'missed_days'  => 'sometimes|integer|min:0',
    ]);

    $collector = InchargeCollector::where('user_id', auth()->id())->first();

    if (!$collector) {
        return response()->json([
            'status' => 'error',
            'message' => 'No collector profile found for this user.'
        ], 403);
    }

    $rented = Rented::with('application.vendor', 'stall')->findOrFail($validated['rented_id']);

    if (!$rented || !$rented->application || !$rented->application->vendor) {
        return response()->json([
            'status' => 'error',
            'message' => 'Vendor not found for this rented stall.'
        ], 404);
    }

    $paymentDate = $validated['payment_date']; // ← Use the provided date
    $missedDays = $validated['missed_days'] ?? 0;

    // -----------------------------
    // 🔵 Compute Total Amount
    // -----------------------------
    if ($validated['payment_type'] === 'daily') {
        $dailyRent = $rented->daily_rent ?? 0;
        $totalAmount = $dailyRent * (1 + $missedDays);
    } else {
        $totalAmount = $validated['amount'];
    }

    // -----------------------------
    // 🔵 Create Payment (PENDING confirmation)
    // -----------------------------
    $payment = Payments::create([
        'rented_id'    => $rented->id,
        'payment_type' => $validated['payment_type'],
        'amount'       => $totalAmount,
        'payment_date' => $paymentDate,
        'missed_days'  => $missedDays,
        'collector_id' => $collector->id,
        'vendor_id'    => $rented->application->vendor->id,
        'status'       => 'pending_confirmation',  // ← IMPORTANT!
    ]);

    // -----------------------------
    // 🔵 Update rented record
    // -----------------------------
    $rented->last_payment_date = $paymentDate;
    $rented->save();

    return response()->json([
        'status'  => 'success',
        'message' => 'Payment recorded and now pending vendor confirmation.',
        'payment' => $payment
    ]);
}





    public function index()
    {
        $registrations = MarketRegistration::with(['vendor', 'application.section', 'application.stall'])->get();
        return response()->json($registrations);
    }


}
