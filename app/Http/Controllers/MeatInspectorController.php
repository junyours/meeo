<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Animals;
use Twilio\Rest\Client;
use App\Models\Notification;
use Illuminate\Http\Request;
use App\Models\MeatInspector;
use App\Models\InspectionRecord;
use App\Models\SlaughterPayment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class MeatInspectorController extends Controller
{
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
        'profile_picture' => 'nullable|image|mimes:jpg,jpeg,png|max:2048', // ✅ validate image
    ]);

    $profilePicturePath = null;
if ($request->hasFile('profile_picture')) {
    $file = $request->file('profile_picture');
    $filename = time() . '_' . $file->getClientOriginalName();

    // 1️⃣ Store file in storage/app/public/profile_pictures
    $profilePicturePath = $file->storeAs('profile_pictures', $filename, 'public');

    // 2️⃣ Copy file to public/storage/profile_pictures
    $storagePath = storage_path('app/public/profile_pictures/' . $filename);
    $publicPath = public_path('storage/profile_pictures/' . $filename);

    // Ensure target directory exists
    if (!file_exists(dirname($publicPath))) {
        mkdir(dirname($publicPath), 0777, true);
    }

    // Copy file from storage to public directory
    copy($storagePath, $publicPath);
}


    $profile = MeatInspector::updateOrCreate(
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



    
     public function show(Request $request)
    {
        $user = $request->user();
        $profile = MeatInspector::where('user_id', $user->id)->first();

        if (!$profile) {
        return response()->json(['message' => 'Profile not found'], 404);
    }

    // Same fix as vendor: wrap profile picture in asset()
    $profile->profile_picture = $profile->profile_picture 
        ? asset('storage/' . $profile->profile_picture) 
        : null;

        return response()->json($profile, 200);
    }


 


        public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:approved,rejected',
        ]);

        $profile = MeatInspector::findOrFail($id);
        $profile->status = $validated['status'];
        $profile->save();

        return response()->json([
            'message' => "Profile status updated to {$validated['status']}",
            'profile' => $profile,
        ], 200);
    }




      public function index()
{
    $profiles = MeatInspector::all()->map(function ($profile) {
        if ($profile->profile_picture) {
            $profile->profile_picture = asset('storage/' . $profile->profile_picture);
        }
        return $profile;
    });

    return response()->json($profiles, 200);
}

    public function healthyAnteMortemAnimals()
{
    // Get animals that have an ante-mortem inspection with 'Healthy' status
    $animalIds = InspectionRecord::where('inspection_type', 'ante-mortem')
        ->where('health_status', 'Healthy')
        ->pluck('animal_id');

    // Return the animal details
    $animals = Animals::whereIn('id', $animalIds)->get();

    return response()->json($animals);
}

public function getTodaysAnimalsByCustomer($customerId)
{
    $today = now()->toDateString();

    // Get animals that have a slaughter payment today for this customer
    $animals = Animals::whereHas('slaughterPayments', function($q) use ($customerId, $today) {
        $q->where('customer_id', $customerId)
          ->whereDate('created_at', $today); // Only today
          // Remove status filter unless you really need it
    })->get();

    return response()->json($animals);
}


    public function getInspectableAnimals()
    {
        $inspector = MeatInspector::where('user_id', Auth::id())->first();
        if (!$inspector) {
            return response()->json(['message' => 'Inspector not found'], 404);
        }

        $animals = Animals::whereHas('slaughterPayments', function($q) use ($inspector) {
            $q->where('inspector_id', $inspector->id)
              ->where('status', 'collected'); // Only collected payments
        })->get();

        return response()->json($animals);
    }

public function storedInspection(Request $request)
{
    $request->validate([
        'animal_id' => 'required|exists:animals,id',
        'customer_id' => 'required|exists:customer_details,id',
        'inspection_type' => 'required|in:ante-mortem,post-mortem',
        'health_status' => 'required|string',
        'defects' => 'nullable|string',
        'remarks' => 'nullable|string',
    ]);

    $inspector = MeatInspector::where('user_id', Auth::id())->first();
    if (!$inspector) {
        return response()->json(['message' => 'Inspector not found'], 404);
    }

    $defects = $request->defects;
    $remarks = $request->remarks;

    if ($request->inspection_type === 'ante-mortem') {
        // ✅ Auto-fill for Ante-Mortem
        switch (strtolower($request->health_status)) {
            case 'healthy':
                $defects = 'No visible defects';
                $remarks = 'Good condition';
                break;
            case 'sick':
                $defects = 'Signs of illness detected';
                $remarks = 'Requires medical attention';
                break;
            case 'injured':
                $defects = 'Physical injury observed';
                $remarks = 'Handle with care';
                break;
            default:
                $defects = 'N/A';
                $remarks = 'N/A';
                break;
        }
    } elseif ($request->inspection_type === 'post-mortem') {
        // ✅ Post-Mortem logic
        switch (strtolower($request->health_status)) {
            case 'healthy':
                $defects = 'No visible defects';
                $remarks = 'Good for human consumption';
                break;
            case 'unhealthy':
                // use what frontend sends
                $defects = $request->defects ?? 'N/A';
                $remarks = $request->remarks ?? 'N/A';
                break;
            default:
                $defects = 'N/A';
                $remarks = 'N/A';
                break;
        }
    }

    $inspection = InspectionRecord::create([
        'animal_id' => $request->animal_id,
        'customer_id' => $request->customer_id,
        'inspection_type' => $request->inspection_type,
        'health_status' => $request->health_status,
        'defects' => $defects,
        'remarks' => $remarks,
        'inspector_id' => $inspector->id,
    ]);

    return response()->json([
        'inspection' => $inspection,
        'message' => 'Inspection recorded successfully.',
    ], 201);
}


public function displayinspection(Request $request)
{
    $inspector = MeatInspector::where('user_id', Auth::id())->first();

    if (!$inspector) {
        return response()->json(['message' => 'Inspector not found'], 404);
    }

    $today = now()->toDateString();

    $inspections = InspectionRecord::where('inspector_id', $inspector->id)
        ->whereDate('created_at', $today)
        ->with(['animal', 'customer'])
        ->get();

    $inspections->transform(function ($inspection) {
        $inspection->notified = (bool) ($inspection->notified ?? false);
        return $inspection;
    });

    return response()->json($inspections);
}

public function notifyCustomer(Request $request, $inspectionId)
{
    $inspection = InspectionRecord::with('animal', 'customer')->findOrFail($inspectionId);
    $customer = $inspection->customer;

    if ($request->has('customer_id') && $customer && $request->customer_id != $customer->id) {
        return response()->json(['message' => 'Customer ID does not match this inspection'], 400);
    }

    if (!$customer) {
        return response()->json(['message' => 'Customer not found'], 404);
    }

    $animalName = $inspection->animal->animal_type ?? 'Animal';
    $status = strtolower($inspection->health_status);
    $defects = $inspection->defects ?? 'No specific defects noted';
    $remarks = $inspection->remarks ?? 'No remarks provided';
    $inspectionType = strtolower($inspection->inspection_type);

    // ✅ If Ante-Mortem (Healthy) — send a simple message about proceeding to Post-Mortem
    if ($inspectionType === 'ante-mortem' && strtolower($status) === 'healthy') {
        $message = "Inspection Alert: Your {$animalName} for slaughter has passed the ante-mortem inspection and will proceed to post-mortem inspection.";
    }
    // ✅ Post-Mortem Notification Logic
    else {
        if (strtolower($remarks) === 'good for human consumption') {
            $actionLine = "Please proceed to the Zone 4 Taboc Opol Mis.Or Slaughterhouse immediately to pay the associated fees.";
        } else {
            $actionLine = "Please proceed to the Zone 4 Taboc Opol Mis.Or Slaughterhouse immediately for further details.";
        }

        $message = "Inspection Alert: Your {$animalName} for slaughter has been inspected and found {$status}. "
                 . "Defects observed: {$defects}. Remarks: {$remarks}. {$actionLine}";
    }

    Notification::create([
        'customer_id' => $customer->id,
        'title'       => 'Inspection Alert',
        'message'     => $message,
        'is_read'     => 0,
    ]);

    $inspection->update(['notified' => true]);

    return response()->json([
        'success' => true,
        'message' => 'Notification created successfully',
    ]);
}

public function allInspections()
{
    $inspector = MeatInspector::where('user_id', Auth::id())->first();

    if (!$inspector) {
        return response()->json(['message' => 'Inspector not found'], 404);
    }

    $inspections = InspectionRecord::where('inspector_id', $inspector->id)
        ->with(['animal', 'customer'])
        ->orderBy('created_at', 'desc')
        ->get();

    return response()->json($inspections);
}

  
public function audithistory()
{
    try {
        $payments = SlaughterPayment::with([
            'customer:id,fullname',
            'animal:id,animal_type',
            'collector:id,fullname'
        ])
        ->orderBy('payment_date', 'desc')
        ->get();

        $formatted = $payments->map(function($item) {
            return [
                'id'            => $item->id,
                'customer_name' => $item->customer?->fullname ?? 'N/A',
                'animal'        => [
                    'animal_type' => $item->animal?->animal_type ?? 'N/A'
                ],
                'quantity'      => $item->quantity ?? 0,
                'total_kilos'   => $item->total_kilos ?? 0,
                'per_kilos'     => $item->per_kilos ?? null, // casted as array
                'slaughter_fee' => $item->slaughter_fee ?? 0,
                'ante_mortem'   => $item->ante_mortem ?? 0,
                'post_mortem'   => $item->post_mortem ?? 0,
                'coral_fee'     => $item->coral_fee ?? 0,
                'permit_to_slh' => $item->permit_to_slh ?? 0,
                'total_amount'  => $item->total_amount ?? 0,
                'status'        => $item->status ?? 'N/A',
                'collector'     => $item->collector ? [
                    'fullname' => $item->collector->fullname
                ] : null,
                'created_at'    => $item->created_at,
            ];
        });

        return response()->json($formatted);
    } catch (\Exception $e) {
        Log::error('SlaughterPayments Fetch Error: '.$e->getMessage());
        return response()->json(['message' => 'Failed to fetch audit history'], 500);
    }
}

    
}
