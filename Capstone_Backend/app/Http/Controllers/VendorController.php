<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Rented;
use App\Models\Stalls;
use App\Models\Payments;
use App\Models\Application;
use App\Models\Notification;
use Illuminate\Http\Request;
use App\Models\VendorDetails;
use App\Models\InchargeCollector;
use Illuminate\Support\Facades\DB;
use App\Models\StallRemovalRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class VendorController extends Controller
{

  public function blocklisted()
    {
      $vendors = Rented::with(['application.vendor', 'stall'])
    ->where('status', 'unoccupied')
    ->where('missed_days', '>', 10)
    ->get();
        return response()->json($vendors);
    }
public function pendingPayments(Request $request)
{
    // Get vendor profile from authenticated user
    $vendor = VendorDetails::where('user_id', Auth::id())->first();

    if (!$vendor) {
        return response()->json(['message' => 'Vendor profile not found'], 404);
    }

    // Fetch all payments pending confirmation for this vendor
    $payments = Payments::with(['rented', 'collector']) // include relationships for frontend
        ->where('vendor_id', $vendor->id)
        ->where('status', 'pending_confirmation')
        ->get();

    return response()->json(['payments' => $payments]);
}

public function confirmPayment(Request $request, $id)
{
    $payment = Payments::with('rented')->findOrFail($id);

    if ($payment->status !== 'pending_confirmation') {
        return response()->json(['message' => 'Payment is not pending confirmation.'], 400);
    }

    // Get input
    $missedDaysToPay = (int) $request->input('missed_days_to_pay', 0);
    $payOnlyToday    = (bool) $request->input('pay_only_today', false);
        $totalAmount     = (float) $request->input('total_amount_to_be_paid', 0); // <-- from frontend


    // If vendor chooses pay only today, we ignore any missed days payment
    if ($payOnlyToday) {
        $missedDaysToPay = 0;
    }

    $rented = $payment->rented; // assuming Payments model has rented() relationship

    if ($rented) {
        // Original missed days from rented (or from payment record)
        $originalMissed = $rented->missed_days ?? $payment->missed_days ?? 0;

        if ($missedDaysToPay < 0) {
            return response()->json([
                'message' => 'Invalid missed_days_to_pay value.'
            ], 422);
        }

        // Compute remaining missed days
        $remainingMissed = max($originalMissed - $missedDaysToPay, 0);

        // ✅ Update payment status and date
        $payment->status       = 'collected';
        $payment->updated_at   = now();
        $payment->payment_date = now();
    $payment->amount       = $totalAmount;
        // ✅ Store remaining missed days in payments table too
        $payment->missed_days  = $remainingMissed;

        $payment->save();

        // ✅ Update the related rented stall with the same remaining missed days
        $rented->last_payment_date = $payment->payment_date;
        $rented->missed_days       = $remainingMissed;

        // For daily payments, next due date is tomorrow
        $rented->next_due_date = now()->addDay()->format('Y-m-d');
     
        $rented->save();
    }

    return response()->json([
        'message' => 'Payment successfully confirmed.',
        'payment' => $payment,
        'rented'  => $rented
    ]);
}





public function store(Request $request)
{
    $validated = $request->validate([
        'fullname' => 'required|string|max:255',
        'age' => 'required|integer|min:18|max:100',
        'gender' => 'required|string|in:male,female,others',
        'contact_number' => 'required|string|max:20',
        'emergency_contact' => 'required|string|max:50',
        'address' => 'required|string|max:255',
        'profile_picture' => 'nullable|file|mimes:jpeg,png,jpg,gif|max:5120', // 5MB
        'Business_permit' => 'nullable|file|mimes:jpeg,png,jpg,gif|max:5120',
        'Sanitary_permit' => 'nullable|file|mimes:jpeg,png,jpg,gif|max:5120',
        'Dti_permit' => 'nullable|file|mimes:jpeg,png,jpg,gif|max:5120',
    ]);

    $data = $validated;

// Handle profile picture
if ($request->hasFile('profile_picture')) {
    $file = $request->file('profile_picture');
    $filename = time() . '_' . $file->getClientOriginalName();

    // Store inside storage/app/public/profile_pictures
    $path = $file->storeAs('profile_pictures', $filename, 'public');
    $data['profile_picture'] = $path;

    // Also copy to public/storage/profile_pictures
    $publicPath = public_path('storage/profile_pictures/' . $filename);
    if (!file_exists(public_path('storage/profile_pictures'))) {
        mkdir(public_path('storage/profile_pictures'), 0777, true);
    }
    copy(storage_path('app/public/profile_pictures/' . $filename), $publicPath);
} else {
    unset($data['profile_picture']);
}

// Handle permits
foreach (['Business_permit', 'Sanitary_permit', 'Dti_permit'] as $permit) {
    if ($request->hasFile($permit)) {
        $file = $request->file($permit);
        $filename = time() . '_' . $file->getClientOriginalName();

        // Store inside storage/app/public/permits
        $path = $file->storeAs('permits', $filename, 'public');
        $data[$permit] = $path;

        // Also copy to public/storage/permits
        $publicPath = public_path('storage/permits/' . $filename);
        if (!file_exists(public_path('storage/permits'))) {
            mkdir(public_path('storage/permits'), 0777, true);
        }
        copy(storage_path('app/public/permits/' . $filename), $publicPath);
    } else {
        unset($data[$permit]);
    }
}


    $vendor = VendorDetails::updateOrCreate(
        ['user_id' => Auth::id()],
        array_merge($data, ['Status' => 'pending'])
    );

       Notification::create([
       
        'message' => 'A new vendor profiling has been submitted and is pending approval.',
        'title' => 'New Vendor Profiling',
        'is_read' => 0,
    ]);

    return response()->json($vendor);
}


public function getVendorRentedHistory()
{
    $vendor = VendorDetails::where('user_id', Auth::id())->first();

    if (!$vendor) {
        return response()->json(['message' => 'Vendor profile not found'], 404);
    }

    // Get all applications of this vendor
    $applicationIds = Application::where('vendor_id', $vendor->id)->pluck('id');

    // Pull rented history for these applications
    $rentedHistory = Rented::with(['stall.section', 'application'])
        ->whereIn('application_id', $applicationIds)
        ->orderBy('created_at', 'desc')
        ->get()
        ->map(function ($rented) {
            return [
                'id' => $rented->id,
                'stall_number' => $rented->stall->stall_number ?? null,
                'section' => $rented->stall->section->name ?? null,
                'daily_rent' => $rented->daily_rent,
                'monthly_rent' => $rented->monthly_rent,
                'status' => $rented->status,
                'last_payment_date' => $rented->last_payment_date?->toDateString(),
                'next_due_date' => $rented->next_due_date,
                'missed_days' => $rented->missed_days,
                'created_at' => $rented->created_at->toDateString(),
            ];
        });

    return response()->json([
        'success' => true,
        'history' => $rentedHistory
    ]);
}

public function show()
{
    $vendor = VendorDetails::where('user_id', Auth::id())->first();

    if (!$vendor) {
        return response()->json(['message' => 'Vendor profile not found'], 404);
    }

    // ✅ Use the real DB columns: business_permit, sanitary_permit, dti_permit
    $vendor->business_permit = $vendor->business_permit 
        ? asset('storage/' . $vendor->business_permit) 
        : null;

    $vendor->sanitary_permit = $vendor->sanitary_permit 
        ? asset('storage/' . $vendor->sanitary_permit) 
        : null;

    $vendor->dti_permit = $vendor->dti_permit 
        ? asset('storage/' . $vendor->dti_permit) 
        : null;

    // (Optional) remove the capitalized ones if they exist so response is clean
    unset(
        $vendor->Business_permit,
        $vendor->Sanitary_permit,
        $vendor->Dti_permit
    );

    return response()->json($vendor);
}



    public function vendor()
    {
    
        $vendors = VendorDetails::select('id', 'fullname')->get();

        return response()->json($vendors);
    }



public function getRentedStallsForVendor()
{
    $userId = auth()->id();
    $vendor = VendorDetails::where('user_id', $userId)->first();
    if (!$vendor) {
        return response()->json(['message' => 'Vendor not found'], 404);
    }

    $applicationIds = Application::where('vendor_id', $vendor->id)->pluck('id');

    $rentedStalls = Rented::whereIn('application_id', $applicationIds)
        ->where('status', 'occupied')
        ->with('stall.section', 'payments', 'application')
        ->get()
        ->map(function ($rented) {
            $today = Carbon::today();

            // Get latest payment with relevant statuses
            $lastPayment = $rented->payments()
                ->whereIn('status', ['remitted', 'collected', 'pending_confirmation','pending'])
                ->orderByDesc('payment_date')
                ->first();

            // Pending confirmation flag
            $isPendingConfirmation = $lastPayment && $lastPayment->status === 'pending_confirmation';

            $baseDate = $lastPayment
                ? Carbon::parse($lastPayment->payment_date)->startOfDay()
                : $rented->created_at->copy()->startOfDay();

            $advanceDays = $lastPayment ? ($lastPayment->advance_days ?? 0) : 0;
            $paymentType = $lastPayment ? strtolower($lastPayment->payment_type) : 'daily';

            if ($rented->next_due_date) {
                $nextDueDate = Carbon::parse($rented->next_due_date);
            } else {
                $nextDueDate = $paymentType === 'daily'
                    ? ($rented->last_payment_date
                        ? Carbon::parse($rented->last_payment_date)->addDay()
                        : $rented->created_at->copy()->addDay())
                    : $baseDate->copy()->addDays($advanceDays ?: 1);

                $rented->update(['next_due_date' => $nextDueDate]);
            }

            $missedDays = $today->gt($nextDueDate) ? $today->diffInDays($nextDueDate) : 0;
            if ($missedDays !== $rented->missed_days) {
                $rented->update(['missed_days' => $missedDays]);
            }

            $paidToday = $lastPayment ? Carbon::parse($lastPayment->payment_date)->isSameDay($today) : false;

            $hasPendingAdvance = $rented->payments()
                ->where('status', 'pending_confirmation')
                ->where('payment_type', 'advance')
                ->exists();

            $hasActiveAdvance = $rented->payments()
                ->whereIn('status', ['collected', 'remitted'])
                ->where('payment_type', 'advance')
                ->whereDate('payment_date', '<=', $today)
                ->exists();

            $advancePaymentStatus = null;
            if ($hasPendingAdvance) {
                $advancePaymentStatus = 'pending';
            } elseif ($hasActiveAdvance && $today->lt($nextDueDate)) {
                $advancePaymentStatus = 'active';
            }

            $hasDueToday = !$paidToday && $today->isSameDay($nextDueDate);

            $status = 'paid';
            if ($missedDays > 0) {
                $status = 'missed';
            } elseif ($today->isSameDay($nextDueDate)) {
                $status = 'due';
            }

            return [
                'id' => $rented->id,
                'stall_id' => $rented->stall_id,
                'stall_number' => $rented->stall->stall_number,
                'section' => $rented->stall->section ? ['name' => $rented->stall->section->name] : null,
                'daily_rent' => $rented->daily_rent,
                'status' => $status,
                'occupied_status' => $rented->status,
                'is_active' => $rented->stall->is_active,
                'pending_removal' => $rented->stall->pending_removal,
                'message' => $rented->stall->message,
                'is_paid_today' => $paidToday,
                'is_pending_confirmation' => $isPendingConfirmation,
                'last_payment_date' => $lastPayment?->payment_date,
                'missed_days' => $missedDays,
                'advance_days' => $advanceDays,
                'next_due_date' => $nextDueDate->toDateString(),
                'has_due_today' => $hasDueToday,
                'payment_type' => $paymentType,
                'advance_payment_status' => $advancePaymentStatus,
                'vendor_id' => $rented->application->vendor_id,
            ];
        });

    return response()->json(['rented_stalls' => $rentedStalls]);
}



public function removeStall(Request $request, $rentedId)
{
    DB::beginTransaction();
    try {
        $rented = Rented::with('stall', 'application.vendor')->where('id', $rentedId)->first();
        if (!$rented) return response()->json(['message' => 'Rented record not found.'], 404);

        $vendor = $rented->application->vendor ?? null;
        if (!$vendor) return response()->json(['message' => 'Vendor not found.'], 404);

        $vendorName = $vendor->name;
        $hasMissedDays = $rented->missed_days > 0;
        $hasActiveAdvance = $rented->payments()
            ->whereIn('status', ['collected', 'remitted'])
            ->where('payment_type', 'advance')
            ->exists();

        if ($hasMissedDays) return response()->json(['message' => 'Cannot remove stall with missed payments.'], 400);
        if ($hasActiveAdvance) return response()->json(['message' => 'Cannot remove stall with active advance payment.'], 400);

        $stall = $rented->stall;
        if ($stall) {
            $stall->pending_removal = true;
            $stall->save();
        }

        // Save removal reason in stallremoverequest table
        StallRemovalRequest::create([
            'rented_id' => $rented->id,
            'vendor_id' => $vendor->id,
            'stall_id' => $stall->id,
            'message' => $request->input('message', 'No reason provided'),
            'status' => 'pending',
        ]);

        // Notification for admin
        Notification::create([
            'title' => 'Stall Removal Request',
            'message' => "Vendor {$vendorName} requested to remove Stall #{$stall->stall_number}.",
            'is_read' => false,
        ]);

        DB::commit();
        return response()->json(['message' => 'Stall removal request submitted. Admin has been notified.'], 200);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json(['message' => 'Error submitting stall removal request: ' . $e->getMessage()], 500);
    }
}





  public function getVendorPaymentHistory()
    {
        $vendor = VendorDetails::where('user_id', Auth::id())->first();

        if (!$vendor) {
            return response()->json([
                'success' => false,
                'message' => 'Vendor profile not found'
            ], 404);
        }

        $payments = Payments::with(['rented.stall', 'collector'])
            ->where('vendor_id', $vendor->id)
            ->orderBy('payment_date', 'desc')
            ->get()
            ->map(function($p) {
                return [
                    'id' => $p->id,
                    'stall_number' => $p->rented->stall->stall_number ?? 'N/A',
                    'payment_type' => $p->payment_type,
                    'amount' => $p->amount,
                    'missed' => $p->missed_days,
                    'advance' => $p->advance_days,

                    'status' => $p->status,
                    'payment_date' => $p->payment_date ? $p->payment_date->format('Y-m-d') : null,
                    'collector_name' => $p->collector->fullname ?? '-',
                    'created_at' => $p->created_at ? $p->created_at->format('Y-m-d H:i') : null,
                    'updated_at' => $p->updated_at ? $p->updated_at->toDateTimeString() : null,
                ];
            });

        return response()->json([
            'success' => true,
            'payments' => $payments
        ]);
    }

public function getPayments($id)
{
    // Find the rented record along with its payments and collectors
    $rented = Rented::with('payments.collector')->findOrFail($id);

    // Filter payments to only include 'collected' or 'remitted'
    $filteredPayments = $rented->payments
        ->filter(fn($p) => in_array(strtolower($p->status), ['collected', 'remitted']));

    $payments = $filteredPayments->map(function($p) {
        return [
            'payment_type' => $p->payment_type,
            'amount'       => $p->amount,
            'payment_date' => $p->payment_date?->format('F d, Y') ?? '-',
            'missed_days'  => $p->missed_days,
            'advance_days' => $p->advance_days,
            'status'       => ucfirst($p->status), // e.g., Collected / Remitted
            'collector'    => $p->collector?->fullname ?? '-', // include collector name
        ];
    });

    return response()->json($payments);
}



public function payAdvance(Request $request)
{
    $request->validate([
        'rented_id'     => 'required|exists:rented,id',
        'payment_type'  => 'required|string',
        'payment_date'  => 'required|date',
        'advance_days'  => 'required|integer|min:0',
        'amount'        => 'required|numeric|min:0',
        'next_due_date' => 'required|date',
    ]);

    $rented = Rented::with('application.vendor', 'stall')->findOrFail($request->rented_id);
    $vendor = $rented->application->vendor;
    $vendorId = $vendor->id;
    $stallNumber = $rented->stall->stall_number ?? 'N/A';

    $coveredDays = (int) $request->advance_days;
    $amount = (float) $request->amount;
    $nextDueDate = $request->next_due_date;

    // 🧾 Create payment record
    $payment = Payments::create([
        'rented_id'    => $rented->id,
        'collector_id' => null,
        'vendor_id'    => $vendorId,
        'payment_type' => $request->payment_type,
        'amount'       => $amount,
        'payment_date' => $request->payment_date,
        'advance_days' => $coveredDays,
        'missed_days'  => $rented->missed_days ?? 0,
        'status'       => 'pending',
    ]);

    // 🧱 Update rented stall details
    $rented->update([
        'last_payment_date' => now(),
        'missed_days'       => 0,
        'is_paid_today'     => true,
        'next_due_date'     => $nextDueDate,
    ]);

    // 📩 Vendor Notification
    Notification::create([
        'vendor_id' => $vendorId,
        'title'     => 'Advance Payment Recorded',
        'message'   => "You made an advance payment for stall #{$stallNumber} amounting to ₱"
                        . number_format($amount, 2)
                        . ". Your next due date will be on {$nextDueDate}.",
        'is_read'   => 0,
    ]);

    // 📩 Notify all collectors
    $collectors = InchargeCollector::all();
    foreach ($collectors as $collector) {
        Notification::create([
            'collector_id' => $collector->id,
            'title'        => 'Advance Payment',
            'message'      => "{$vendor->fullname} made an advance payment for stall #{$stallNumber}.",
            'is_read'      => 0,
        ]);
    }

    return response()->json([
        'success' => true,
        'message' => 'Advance payment recorded successfully.',
        'payment' => $payment,
    ]);
}





// In CollectorController
public function pendingAdvancePayments()
{
    $payments = Payments::where('payment_type', 'advance')
        ->where('status', 'pending') // <-- only fetch pending
        ->with('vendor', 'rented.stall.section')
        ->get()
        ->map(function ($p) {
            return [
                'payment_id' => $p->id,
                'vendor_name' => $p->vendor->fullname ?? 'Unknown Vendor',
                'stall_number' => $p->rented->stall->stall_number ?? null,
                'section' => $p->rented->stall->section->name ?? null,
                'amount' => $p->amount,
                'payment_date' => $p->payment_date,
                'advance_days' => $p->advance_days,
                'status' => $p->status,
            ];
        });

    return response()->json(['payments' => $payments]);
}

public function collectAdvancePayment(Request $request, $paymentId)
{
    $user = auth()->user(); // logged-in user

    // Get the collector record linked to this user
    $collector = InchargeCollector::where('user_id', $user->id)->first();

    if (!$collector) {
        return response()->json(['message' => 'Collector not found for this user'], 404);
    }

    $payment = Payments::findOrFail($paymentId);

  
if ($payment->status === 'collected') {
    return response()->json(['message' => 'Payment already collected'], 400);
}

    // Assign collector ID from InchargeCollector
    $payment->collector_id = $collector->id;
      $payment->status = 'collected';
    $payment->save();

    return response()->json([
        'success' => true,
        'message' => 'Advance payment collected successfully',
        'payment' => $payment,
    ]);
}



}
