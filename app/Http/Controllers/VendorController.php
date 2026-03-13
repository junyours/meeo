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
        'business_permit' => 'nullable|file|mimes:jpeg,png,jpg,gif|max:5120',
        'sanitary_permit' => 'nullable|file|mimes:jpeg,png,jpg,gif|max:5120',
        'dti_permit' => 'nullable|file|mimes:jpeg,png,jpg,gif|max:5120',
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
foreach (['business_permit', 'sanitary_permit', 'dti_permit'] as $permit) {
    if ($request->hasFile($permit)) {
        Log::info("Processing permit: " . $permit);
        $file = $request->file($permit);
        $filename = time() . '_' . $file->getClientOriginalName();

        // Store inside storage/app/public/permits
        $path = $file->storeAs('permits', $filename, 'public');
        $data[$permit] = $path;
        Log::info("Stored permit path: " . $path);

        // Also copy to public/storage/permits
        $publicPath = public_path('storage/permits/' . $filename);
        if (!file_exists(public_path('storage/permits'))) {
            mkdir(public_path('storage/permits'), 0777, true);
        }
        copy(storage_path('app/public/permits/' . $filename), $publicPath);
    } else {
        Log::info("No file found for permit: " . $permit);
    }
    // Don't unset the permit - keep existing value if no new file uploaded
}


    // Get existing vendor to preserve permits if not updating
    $existingVendor = VendorDetails::where('user_id', Auth::id())->first();
    
    // Map lowercase keys to capitalized database columns
    $mappedData = $data;
    $columnMapping = [
        'business_permit' => 'Business_permit',
        'sanitary_permit' => 'Sanitary_permit', 
        'dti_permit' => 'Dti_permit',
    ];
    
    foreach ($columnMapping as $fromKey => $toKey) {
        if (isset($mappedData[$fromKey])) {
            $mappedData[$toKey] = $mappedData[$fromKey];
            unset($mappedData[$fromKey]);
        }
    }
    
    Log::info("Mapped data being saved to database:", $mappedData);
    
    $vendor = VendorDetails::updateOrCreate(
        ['user_id' => Auth::id()],
        array_merge($mappedData, ['Status' => 'pending'])
    );

    // If updating existing vendor and no new permits uploaded, keep existing permits
    if ($existingVendor) {
        foreach (['business_permit', 'sanitary_permit', 'dti_permit'] as $permit) {
            if (!isset($mappedData[$permit]) && !$request->hasFile($permit)) {
                // Keep existing permit value
                $capitalizedPermit = $columnMapping[$permit];
                $vendor->$capitalizedPermit = $existingVendor->$capitalizedPermit;
            }
        }
        $vendor->save();
    }

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

    // Pull rented history for these applications with more details
    $rentedHistory = Rented::with(['stall.section', 'application', 'payments'])
        ->whereIn('application_id', $applicationIds)
        ->orderBy('created_at', 'desc')
        ->get()
        ->map(function ($rented) {
            // Calculate total payments made
            $totalPayments = $rented->payments()
                ->whereIn('status', ['collected', 'remitted'])
                ->sum('amount');
            
            // Get payment count
            $paymentCount = $rented->payments()
                ->whereIn('status', ['collected', 'remitted'])
                ->count();
            
            // Get last payment date
            $lastPayment = $rented->payments()
                ->whereIn('status', ['collected', 'remitted'])
                ->orderBy('payment_date', 'desc')
                ->first();
            
            // Calculate duration
            $startDate = $rented->created_at;
            $endDate = $rented->updated_at > $rented->created_at ? $rented->updated_at : now();
            $duration = $startDate->diffInDays($endDate);
            
            // Get status display with proper formatting
            switch ($rented->status) {
                case 'occupied':
                    $statusDisplay = 'Occupied';
                    break;
                case 'temp_closed':
                    $statusDisplay = 'Temporarily Closed';
                    break;
                case 'advance':
                    $statusDisplay = 'Advance Payment';
                    break;
                case 'fully paid':
                    $statusDisplay = 'Fully Paid';
                    break;
                case 'partial':
                    $statusDisplay = 'Partial Payment';
                    break;
                case 'unoccupied':
                    $statusDisplay = 'Unoccupied';
                    break;
                default:
                    $statusDisplay = ucfirst($rented->status);
                    break;
            }

            return [
                'id' => $rented->id,
                'stall_number' => $rented->stall->stall_number ?? 'N/A',
                'section' => $rented->stall->section->name ?? 'N/A',
                'daily_rent' => $rented->daily_rent,
                'monthly_rent' => $rented->monthly_rent,
                'status' => $statusDisplay,
                'status_raw' => $rented->status,
                'last_payment_date' => $lastPayment ? $lastPayment->payment_date->toDateString() : null,
                'next_due_date' => $rented->next_due_date,
                'missed_days' => $rented->missed_days,
                'created_at' => $rented->created_at->toDateString(),
                'updated_at' => $rented->updated_at->toDateString(),
                'duration_days' => $duration,
                'total_payments' => $totalPayments,
                'payment_count' => $paymentCount,
                'remaining_balance' => $rented->remaining_balance ?? 0,
                'stall_size' => $rented->stall->size ?? 'N/A',
                'application_date' => $rented->application->created_at ? $rented->application->created_at->toDateString() : null,
                'vendor_name' => $rented->application->vendor->fullname ?? 'N/A',
            ];
        });

    return response()->json([
        'success' => true,
        'history' => $rentedHistory,
        'total_count' => $rentedHistory->count()
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

    $vendor->profile_picture = $vendor->profile_picture 
        ? asset('storage/' . $vendor->profile_picture) 
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

    // Get all rented stalls including inactive ones (to show under maintenance stalls)
    $rentedStalls = Rented::whereIn('application_id', $applicationIds)
        ->with('stall.section', 'payments', 'application')
        ->get();

    $totalRemainingBalance = 0;

    $mappedStalls = $rentedStalls->map(function ($rented) use (&$totalRemainingBalance) {
        $today = now();
        $status = 'occupied'; // default status
        $color = 'red'; // default color for occupied
        $missedDays = 0;
        $nextDueDate = null;
        $remainingBalance = $rented->remaining_balance ?? 0;

        // Get latest payment with relevant statuses
        $lastPayment = $rented->payments()
            ->whereIn('status', ['remitted', 'collected', 'pending_confirmation', 'pending'])
            ->orderByDesc('payment_date')
            ->first();

        // Determine next due date, preferring stored next_due_date (supports advance)
        if ($rented->next_due_date) {
            $nextDueDate = Carbon::parse($rented->next_due_date);
        } else {
            $baseDate = $rented->last_payment_date
                ? Carbon::parse($rented->last_payment_date)
                : Carbon::parse($rented->created_at);
            $nextDueDate = $baseDate->copy()->addDay();
        }

        // If rental is temporarily closed, use stored missed_days and mark distinctly
        if ($rented->status === 'temp_closed') {
            $status = 'temp_closed';
            $missedDays = $rented->missed_days ?? 0;
            $color = '#ff4d00ff'; // same color as StallGrid
        }
        // Skip missed days calculation for inactive stalls
        elseif (!$rented->stall->is_active) {
            // For inactive stalls, use stored values without incrementing
            $missedDays = $rented->missed_days ?? 0;
            $nextDueDate = $rented->next_due_date ? Carbon::parse($rented->next_due_date) : null;
            
            // Set status based on current stored data
            if ($rented->status === 'advance') {
                $status = 'advance';
                $color = '#3b82f6ff';
            } elseif ($rented->status === 'fully paid') {
                $status = 'fully_paid';
                $color = '#074c00ff';
            } elseif ($rented->status === 'partial') {
                $status = 'partial';
                $color = '#00f7ffff';
            } else {
                $status = 'inactive';
                $color = '#9333eaff'; // Purple for inactive/maintenance
            }
        }
        // Explicit advance status: covered until next_due_date
        elseif ($rented->status === 'advance' && $nextDueDate && $today->lte($nextDueDate)) {
            // Still within advance coverage window
            $status = 'advance';
            $missedDays = 0;
            $color = '#3b82f6ff'; // blue (matches legend advance color)
        }
        // Advance period has passed: convert back to occupied so missed logic applies
        elseif ($rented->status === 'advance' && $nextDueDate && $today->gt($nextDueDate)) {
            $rented->status = 'occupied';
            $rented->save();
            // fall through to generic logic below, which will compute missed days
            $paidToday = $rented->last_payment_date &&
                         Carbon::parse($rented->last_payment_date)->isSameDay($today);

            if ($paidToday) {
                $status = 'paid_today';
                $color = 'blue';
            } elseif ($nextDueDate && $today->lte($nextDueDate)) {
                $status = 'occupied';
                $color = 'red';
            } else {
                // If nextDueDate is null, set missed days to 0 and mark as occupied
                $missedDays = $nextDueDate ? $today->diffInDays($nextDueDate) : 0;

                // 🔹 Auto-close when missed days >= 4
                if ($missedDays >= 4) {
                    if ($rented->status !== 'temp_closed') {
                        $rented->status = 'temp_closed';
                        $rented->missed_days = $missedDays;
                        $rented->save();
                    }

                    $status = 'temp_closed';
                    $color = '#ff4d00ff';
                } else {
                    $status = 'missed';
                    $color = 'yellow';
                }
            }
        }
        // Fully paid: no missed days, distinct color
        elseif ($rented->status === 'fully paid') {
            $status = 'fully_paid';
            $missedDays = 0;
            $color = '#074c00ff'; // matches StallGrid fully_paid color
        }
        // Partial payment: still has missed days but marked as partial
        elseif ($rented->status === 'partial') {
            $status = 'partial';
            $missedDays = (int) ($rented->missed_days ?? 0);
            $color = '#00f7ffff';
        }
        else {
            $paidToday = $rented->last_payment_date &&
                         Carbon::parse($rented->last_payment_date)->isSameDay($today);

            if ($paidToday) {
                $status = 'paid_today';
                $color = 'blue';
            } elseif ($nextDueDate && $today->lte($nextDueDate)) {
                $status = 'occupied';
                $color = 'red';
            } else {
                // If nextDueDate is null, set missed days to 0 and mark as occupied
                $missedDays = $nextDueDate ? $today->diffInDays($nextDueDate) : 0;

                // 🔹 Auto-close when missed days >= 4
                if ($missedDays >= 4) {
                    // Update DB once so rented.status is consistent everywhere
                    if ($rented->status !== 'temp_closed') {
                        $rented->status = 'temp_closed';
                        $rented->missed_days = $missedDays;
                        $rented->save();
                    }

                    $status = 'temp_closed';
                    $color = '#ff4d00ff'; // same color as StallGrid
                } else {
                    $status = 'missed';
                    
                    // Color coding based on missed days
                    if ($missedDays === 1) {
                        $color = '#f9ca24ff'; // Yellow for 1 day missed
                    } elseif ($missedDays === 2) {
                        $color = '#ffa502ff'; // Orange for 2 days missed
                    } elseif ($missedDays === 3) {
                        $color = '#ff6348ff'; // Red-orange for 3 days missed
                    } else {
                        $color = '#ff4757ff'; // Dark red for 4+ days missed
                    }
                }
            }
        }

        // Calculate remaining balance based on missed days and daily rent
        if ($status !== 'temp_closed' && $status !== 'inactive') {
            // For active stalls, calculate based on missed days
            $calculatedBalance = $missedDays * $rented->daily_rent;
            
            // Use existing remaining_balance if it exists and is greater than 0, otherwise use calculated
            if ($rented->remaining_balance && $rented->remaining_balance > 0) {
                $remainingBalance = $rented->remaining_balance;
            } else {
                $remainingBalance = $calculatedBalance;
            }
        } elseif ($status === 'temp_closed') {
            // For temp_closed, use the stored remaining balance if exists, otherwise calculate
            if ($rented->remaining_balance && $rented->remaining_balance > 0) {
                $remainingBalance = $rented->remaining_balance;
            } else {
                $remainingBalance = $missedDays * $rented->daily_rent;
            }
        } elseif ($status === 'inactive') {
            // For inactive stalls, use stored remaining balance if exists, otherwise calculate
            if ($rented->remaining_balance && $rented->remaining_balance > 0) {
                $remainingBalance = $rented->remaining_balance;
            } else {
                $remainingBalance = $missedDays * $rented->daily_rent;
            }
        } else {
            $remainingBalance = 0;
        }

        // Update the rented record with new values if changed (but not for inactive stalls)
        if ($missedDays !== $rented->missed_days && $status !== 'temp_closed' && $status !== 'inactive') {
            $rented->update(['missed_days' => $missedDays]);
        }
        if ($remainingBalance !== $rented->remaining_balance && $status !== 'inactive') {
            $rented->update(['remaining_balance' => $remainingBalance]);
        }

        // Add to total remaining balance (exclude temp_closed and inactive from total)
        if ($status !== 'temp_closed' && $status !== 'inactive' && $remainingBalance > 0) {
            $totalRemainingBalance += $remainingBalance;
        }

        // Additional payment logic for vendor-specific features
        $isPendingConfirmation = $lastPayment && $lastPayment->status === 'pending_confirmation';
        $advanceDays = $lastPayment ? ($lastPayment->advance_days ?? 0) : 0;
        $paymentType = $lastPayment ? strtolower($lastPayment->payment_type) : 'daily';

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
        } elseif ($hasActiveAdvance && $nextDueDate && $today->lt($nextDueDate)) {
            $advancePaymentStatus = 'active';
        }

        $hasDueToday = !$isPendingConfirmation && $nextDueDate && $today->isSameDay($nextDueDate);

        // Check if stall is newly rented (created today, no missed days, no payment today)
        $isNewlyRented = $rented->created_at->isSameDay($today) && 
                        $missedDays === 0 && 
                        !$rented->last_payment_date && 
                        !$isPendingConfirmation &&
                        $rented->status === 'occupied';

        return [
            'id' => $rented->id,
            'stall_id' => $rented->stall_id,
            'stall_number' => $rented->stall->stall_number,
            'section' => $rented->stall->section ? ['name' => $rented->stall->section->name] : null,
            'daily_rent' => $rented->daily_rent,
            'status' => $status,
            'color' => $color,
            'occupied_status' => $rented->status,
            'is_active' => $rented->stall->is_active,
            'pending_removal' => $rented->stall->pending_removal,
            'message' => $rented->stall->message,
            'is_paid_today' => $lastPayment ? Carbon::parse($lastPayment->payment_date)->isSameDay($today) : false,
            'is_pending_confirmation' => $isPendingConfirmation,
            'last_payment_date' => $lastPayment ? $lastPayment->payment_date : null,
            'missed_days' => $missedDays,
            'advance_days' => $advanceDays,
            'next_due_date' => $nextDueDate ? $nextDueDate->toDateString() : null,
            'has_due_today' => $hasDueToday,
            'payment_type' => $paymentType,
            'advance_payment_status' => $advancePaymentStatus,
            'vendor_id' => $rented->application->vendor_id,
            'remaining_balance' => $remainingBalance,
            'newly_rented' => $isNewlyRented,
        ];
    });

    return response()->json([
        'rented_stalls' => $mappedStalls,
        'total_remaining_balance' => $totalRemainingBalance,
    ]);
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

        $payments = Payments::with(['rented.stall.section', 'rented.application', 'collector'])
            ->where('vendor_id', $vendor->id)
            ->orderBy('payment_date', 'desc')
            ->get()
            ->map(function($p) {
                // Get payment status display
                switch ($p->status) {
                    case 'collected':
                        $statusDisplay = 'Collected';
                        break;
                    case 'remitted':
                        $statusDisplay = 'Remitted';
                        break;
                    case 'pending':
                        $statusDisplay = 'Pending';
                        break;
                    case 'failed':
                        $statusDisplay = 'Failed';
                        break;
                    default:
                        $statusDisplay = ucfirst($p->status);
                        break;
                }

                // Get payment type display
                switch ($p->payment_type) {
                    case 'daily':
                        $paymentTypeDisplay = 'Daily Payment';
                        break;
                    case 'monthly':
                        $paymentTypeDisplay = 'Monthly Payment';
                        break;
                    case 'advance':
                        $paymentTypeDisplay = 'Advance Payment';
                        break;
                    case 'partial':
                        $paymentTypeDisplay = 'Partial Payment';
                        break;
                    case 'penalty':
                        $paymentTypeDisplay = 'Penalty Payment';
                        break;
                    default:
                        $paymentTypeDisplay = ucfirst($p->payment_type);
                        break;
                }

                // Calculate late fees if any
                $lateFee = 0;
                if ($p->missed_days > 0) {
                    $lateFee = $p->missed_days * ($p->rented->daily_rent * 0.1); // 10% of daily rent per missed day
                }

                return [
                    'id' => $p->id,
                    'stall_number' => $p->rented->stall->stall_number ?? 'N/A',
                    'section' => $p->rented->stall->section->name ?? 'N/A',
                    'stall_size' => $p->rented->stall->size ?? 'N/A',
                    'payment_type' => $paymentTypeDisplay,
                    'payment_type_raw' => $p->payment_type,
                    'amount' => $p->amount,
                    'late_fee' => $lateFee,
                    'total_amount' => $p->amount + $lateFee,
                    'missed_days' => $p->missed_days,
                    'advance_days' => $p->advance_days,
                    'status' => $statusDisplay,
                    'status_raw' => $p->status,
                    'payment_date' => $p->payment_date ? $p->payment_date->format('Y-m-d') : null,
                    'payment_time' => $p->payment_date ? $p->payment_date->format('H:i:s') : null,
                    'collector_name' => $p->collector->fullname ?? 'System',
                    'collector_id' => $p->collector->id ?? null,
                    'receipt_number' => $p->receipt_number ?? 'N/A',
                    'payment_method' => $p->payment_method ?? 'Cash',
                    'notes' => $p->notes ?? null,
                    'created_at' => $p->created_at ? $p->created_at->format('Y-m-d H:i:s') : null,
                    'updated_at' => $p->updated_at ? $p->updated_at->toDateTimeString() : null,
                    'vendor_name' => $p->rented->application->vendor->fullname ?? 'N/A',
                    'monthly_rent' => $p->rented->monthly_rent ?? 0,
                    'daily_rent' => $p->rented->daily_rent ?? 0,
                ];
            });

        // Calculate summary statistics
        $totalCollected = $payments->where('status_raw', 'collected')->sum('amount');
        $totalRemitted = $payments->where('status_raw', 'remitted')->sum('amount');
        $totalPending = $payments->where('status_raw', 'pending')->sum('amount');
        $totalLateFees = $payments->sum('late_fee');
        $paymentCount = $payments->whereIn('status_raw', ['collected', 'remitted'])->count();

        return response()->json([
            'success' => true,
            'payments' => $payments,
            'summary' => [
                'total_collected' => $totalCollected,
                'total_remitted' => $totalRemitted,
                'total_pending' => $totalPending,
                'total_late_fees' => $totalLateFees,
                'payment_count' => $paymentCount,
                'average_payment' => $paymentCount > 0 ? $totalCollected / $paymentCount : 0,
            ],
            'total_count' => $payments->count()
        ]);
    }

public function getPayments($id)
{
    // Find the rented record along with its payments and collectors
    $rented = Rented::with('payments.collector')->findOrFail($id);

    // Filter payments to only include 'collected' or 'remitted'
    $filteredPayments = $rented->payments
        ->filter(function($p) {
            return in_array(strtolower($p->status), ['collected', 'remitted']);
        });

    $payments = $filteredPayments->map(function($p) {
        return [
            'payment_type' => $p->payment_type,
            'amount'       => $p->amount,
            'payment_date' => $p->payment_date ? $p->payment_date->format('F d, Y') : '-',
            'missed_days'  => $p->missed_days,
            'advance_days' => $p->advance_days,
            'status'       => ucfirst($p->status), // e.g., Collected / Remitted
            'collector'    => $p->collector ? $p->collector->fullname : '-', // include collector name
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
