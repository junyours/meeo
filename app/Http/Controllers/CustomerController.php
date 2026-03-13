<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Notification;
use Illuminate\Http\Request;
use App\Models\SlaughterPayment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class CustomerController extends Controller
{

public function metrics(Request $request)
{
    // Get the authenticated customer
    $customer = Customer::where('user_id', auth()->id())->first();

    if (!$customer) {
        return response()->json([
            'pending_payments' => 0,
            'completed_payments' => 0,
            'total_animals' => 0,
            'last_payment_date' => null,
        ]);
    }

    $customerId = $customer->id;

    // Pending payments
    $pendingPayments = SlaughterPayment::where('customer_id', $customerId)
                        ->where('status', 'pending_collection')
                        ->sum('total_amount');

    // Completed payments
    $completedPayments = SlaughterPayment::where('customer_id', $customerId)
                          ->where('status', 'remitted')
                          ->sum('total_amount');

    // Total slaughtered animals
    $totalAnimals = SlaughterPayment::where('customer_id', $customerId)->count();

    // Last payment date (latest payment)
    $lastPayment = SlaughterPayment::where('customer_id', $customerId)
                    ->orderBy('created_at', 'desc')
                    ->first();

    $lastPaymentDate = $lastPayment ? $lastPayment->created_at->format('Y-m-d') : null;

    return response()->json([
        'pending_payments' => $pendingPayments,
        'completed_payments' => $completedPayments,
        'total_animals' => $totalAnimals,
        'last_payment_date' => $lastPaymentDate,
    ]);
}


public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'fullname' => 'required|string|max:255',
            'age' => 'required|string|max:10',
            'gender' => 'required|string|max:20',
            'contact_number' => 'required|string|max:20',
            'emergency_contact' => 'nullable|string|max:20',
            'address' => 'required|string|max:255',
            'profile_picture' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        $profilePicturePath = null;
        if ($request->hasFile('profile_picture')) {
            $profilePicturePath = $request->file('profile_picture')->store('profile_pictures', 'public');
        }

        $customer = Customer::updateOrCreate(
            ['user_id' => $user->id],
            [
                'fullname' => $validated['fullname'],
                'age' => $validated['age'],
                'gender' => $validated['gender'],
                'contact_number' => $validated['contact_number'],
                'emergency_contact' => $validated['emergency_contact'] ?? null,
                'address' => $validated['address'],
                'profile_picture' => $profilePicturePath,
                'status' => 'pending',
            ]
        );

        return response()->json($customer, 201);
    }

    // ✅ Get customer list for Meat Inspector
   public function index()
{
    $customers = Customer::orderBy('created_at', 'desc')
        ->get()
        ->map(function ($customer) {
            return [
                'id' => $customer->id,
                'fullname' => $customer->fullname,
                'age' => $customer->age,
                'gender' => $customer->gender,
                'contact_number' => $customer->contact_number,
                'emergency_contact' => $customer->emergency_contact,
                'address' => $customer->address,
                'status' => $customer->status,
                // Full URL for profile picture
                'profile_picture' => $customer->profile_picture
                    ? asset('storage/' . $customer->profile_picture)
                    : null,
            ];
        });

    return response()->json($customers);
}


    // ✅ Approve or decline customer
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:approved,declined'
        ]);

        $customer = Customer::findOrFail($id);
        $customer->status = $request->status;
        $customer->save();

        return response()->json($customer);
    }


    public function show(Request $request)
{
    $user = $request->user();

    if (!$user) {
        return response()->json([
            'fullname' => '',
            'age' => '',
            'gender' => '',
            'contact_number' => '',
            'address' => '',
            'profile_picture' => null,
        ], 200);
    }

    $profile = Customer::where('user_id', $user->id)->first();

    if (!$profile) {
        return response()->json([
            'fullname' => '',
            'age' => '',
            'gender' => '',
            'contact_number' => '',
            'address' => '',
            'profile_picture' => null,
        ], 200);
    }

    return response()->json([
        'fullname' => $profile->fullname,
        'age' => $profile->age,
        'gender' => $profile->gender,
        'contact_number' => $profile->contact_number,
        'address' => $profile->address,
        'profile_picture' => $profile->profile_picture
            ? asset('storage/' . $profile->profile_picture)
            : null,
    ], 200);
}



 public function notification()
{
    $customerId = Auth::id();

    $customer = Customer::where('user_id', $customerId)->first();

    if (!$customer) {
        return response()->json([
            'status' => 'error',
            'message' => 'Unauthorized. Only customers can check notifications.'
        ], 403);
    }

    // Fetch notifications for this customer, latest first
    $notifications = Notification::where('customer_id', $customer->id)
        ->orderBy('created_at', 'desc')
        ->get();

    // Count unread notifications
    $unreadCount = $notifications->where('is_read', 0)->count();

    return response()->json([
        'notifications' => $notifications,
        'unread_count' => $unreadCount,
    ]);
}

    /**
     * Mark a specific notification as read
     */
 public function markAsRead($id)
{
    // Check if customer profile exists for logged-in user
    $customerId = Customer::where('user_id', auth()->id())->value('id');

    if (!$customerId) {
        return response()->json([
            'status' => 'error',
            'message' => 'Customer profile not found',
        ], 404);
    }

    // Find the notification for this customer
    $notification = Notification::where('customer_id', $customerId)
        ->where('id', $id)
        ->first();

    if (!$notification) {
        return response()->json([
            'status' => 'error',
            'message' => 'Notification not found',
        ], 404);
    }

    // Mark notification as read
    $notification->is_read = true;
    $notification->save();

    return response()->json([
        'status' => 'success',
        'message' => 'Notification marked as read',
        'notification' => $notification,
    ]);
}

public function paymentHistory(Request $request)
{
    // Get the customer ID based on the logged-in user
    $customerId = Customer::where('user_id', auth()->id())->value('id');

    if (!$customerId) {
        return response()->json([
            'status' => 'error',
            'message' => 'Customer profile not found',
        ], 404);
    }

    // Fetch remitted payments for this customer
    $payments = SlaughterPayment::with(['animal', 'collector', 'inspector'])
        ->where('customer_id', $customerId)
        ->where('status', 'remitted')
        ->orderBy('payment_date', 'desc')
        ->get();

    return response()->json([
        'status' => 'success',
        'payments' => $payments
    ]);
}

}
