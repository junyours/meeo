<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Payments;
use App\Models\Notification;
use Illuminate\Http\Request;
use App\Models\MainCollector;
use App\Models\SlaughterPayment;
use App\Models\InchargeCollector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class InchargeCollectorController extends Controller
{

    public function collectorInfo(Request $request)
{
    $assignment = InchargeCollector::where('user_id', $request->user()->id)->first();
    return response()->json([
        'assigned_to' => $assignment->area ?? null,
    ]);
}

public function show(Request $request)
{
    $user = $request->user();
    $profile = InchargeCollector::where('user_id', $user->id)->first();

    if (!$profile) {
        return response()->json(['message' => 'Profile not found'], 404);
    }

    // Same fix as vendor: wrap profile picture in asset()
    $profile->profile_picture = $profile->profile_picture 
        ? asset('storage/' . $profile->profile_picture) 
        : null;

    return response()->json($profile, 200);
}


    // POST create or update profile
  public function store(Request $request)
{
    $user = $request->user();

    $validated = $request->validate([
        'fullname' => 'required|string|max:255',
        'age' => 'required|string|max:10',
        'gender' => 'required|string|max:20',
        'contact_number' => 'required|string|max:20',
        'emergency_contact' => 'required|string|max:20',
        'address' => 'required|string|max:255',
     'profile_picture' => 'nullable|image|mimes:jpg,jpeg,png|max:5120',// ✅ validate image
    ]);

    $profilePicturePath = null;

    if ($request->hasFile('profile_picture')) {
        $profilePicturePath = $request->file('profile_picture')->store('profile_pictures', 'public');
    }

    $profile = InchargeCollector::updateOrCreate(
        ['user_id' => $user->id],
        [
            'fullname' => $validated['fullname'],
            'age' => $validated['age'],
            'gender' => $validated['gender'],
            'contact_number' => $validated['contact_number'],
            'emergency_contact' => $validated['emergency_contact'],
            'address' => $validated['address'],
            'Status' => 'pending',
            'profile_picture' => $profilePicturePath, // ✅ save path
        ]
    );

    return response()->json($profile, 201);
}


  public function index()
{
    $profiles = InchargeCollector::all()->map(function ($profile) {
        if ($profile->profile_picture) {
            $profile->profile_picture = asset('storage/' . $profile->profile_picture);
        }
        return $profile;
    });

    return response()->json($profiles, 200);
}

    /**
     * ADMIN: Approve/Reject an incharge profile
     */
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:approved,rejected',
        ]);

        $profile = InchargeCollector::findOrFail($id);
        $profile->status = $validated['status'];
        $profile->save();

        return response()->json([
            'message' => "Profile status updated to {$validated['status']}",
            'profile' => $profile,
        ], 200);
    }

    /**
     * ADMIN: Assign area (only if approved)
     */
    public function assignArea(Request $request, $id)
    {
        $validated = $request->validate([
            'area' => 'required|string|max:255',
        ]);

        $profile = InchargeCollector::findOrFail($id);

        if ($profile->Status !== 'approved') {
            return response()->json([
                'message' => 'Cannot assign area unless profile is approved.'
            ], 400);
        }

        $profile->area = $validated['area'];
        $profile->save();

        return response()->json([
            'message' => "Area '{$validated['area']}' assigned successfully.",
            'profile' => $profile,
        ], 200);
    }

public function collectorPendingRemitNotification(Request $request)
{
    $user = auth()->user(); 
    $collector = InchargeCollector::where('user_id', $user->id)->first();

    if (!$collector) {
        return response()->json(['message' => 'Collector not found'], 404);
    }

    $query = Notification::where('collector_id', $collector->id);

    // Only show "Payment Advance" notifications to collectors in the market area
    if ($collector->area === 'market') {
        $query->where(function ($q) {
            $q->where('title', 'Payment Advance')
              ->orWhere('title', '!=', 'Payment Advance'); // include other notifications
        });
    } else {
        // Non-market collectors should **not see Payment Advance notifications**
        $query->where('title', '!=', 'Payment Advance');
    }

    $notifications = $query->orderBy('created_at', 'desc')
        ->get(['id', 'message', 'is_read', 'created_at', 'title']);

    return response()->json(['notifications' => $notifications]);
}




public function markAsReadIncharge($id)
{
    // Get collector ID for logged-in user
    $collector = InchargeCollector::where('user_id', auth()->id())->value('id');

    Log::info('Mark Notification Read Attempt', [
        'user_id' => auth()->id(),
        'collector_id' => $collector,
        'notification_id' => $id
    ]);

    if (!$collector) {
        Log::warning('Collector profile not found', ['user_id' => auth()->id()]);
        return response()->json([
            'status' => 'error',
            'message' => 'Collector profile not found',
        ], 404);
    }

    // Try to find the notification
    $notification = Notification::where('collector_id', $collector)
        ->where('id', $id)
        ->first();

    if (!$notification) {
        Log::warning('Notification not found', [
            'collector_id' => $collector,
            'notification_id' => $id
        ]);
        return response()->json([
            'status' => 'error',
            'message' => 'Notification not found',
        ], 404);
    }

    $notification->update(['is_read' => 1]);

    Log::info('Notification marked as read', [
        'collector_id' => $collector,
        'notification_id' => $id
    ]);

    return response()->json([
        'status' => 'success',
        'message' => 'Notification marked as read',
        'notification' => $notification
    ]);
}


public function alreadyRemittedToday()
{
    $userId = Auth::id();

    $staff = InchargeCollector::where('user_id', $userId)->first();

    if (!$staff) {
        return response()->json([
            'status' => 'error',
            'message' => 'Unauthorized. Only incharge collectors can check remittance.'
        ], 403);
    }

    $today = Carbon::today();

    $alreadyRemitted = SlaughterPayment::where('collector_id', $staff->id)
        ->whereDate('updated_at', $today)
        ->where('is_remitted', true)
        ->exists();

    return response()->json([
        'status' => 'success',
        'already_remitted' => $alreadyRemitted
    ]);
}

public function alreadyRemittedMarket()
{
    $userId = Auth::id();

    $staff = InchargeCollector::where('user_id', $userId)->first();

    if (!$staff) {
        return response()->json([
            'status' => 'error',
            'message' => 'Unauthorized. Only incharge collectors can check remittance.'
        ], 403);
    }

    $today = Carbon::today();

    $alreadyRemitted = Payments::where('collector_id', $staff->id)
        ->whereDate('created_at', $today)
        ->where('status', 'remitted') // assuming status 'remitted' is used
        ->exists();

    return response()->json([
        'status' => 'success',
        'already_remitted' => $alreadyRemitted
    ]);
}

}
