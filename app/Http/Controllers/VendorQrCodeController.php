<?php

namespace App\Http\Controllers;

use App\Models\Rented;
use App\Models\VendorDetails;
use App\Models\VendorQrCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VendorQrCodeController extends Controller
{
    private const CURRENT_RENTAL_STATUSES = [
        'active',
        'occupied',
        'advance',
        'temp_closed',
        'partial',
        'fully paid',
    ];

    public function index()
    {
        return VendorDetails::query()
            ->whereHas('rented', function ($query) {
                $query->whereIn('status', self::CURRENT_RENTAL_STATUSES)
                    ->whereHas('stall');
            })
            ->with([
                'qrCode' => static fn ($query) => $query
                    ->where('is_active', true)
                    ->select('id', 'vendor_id', 'qr_token', 'is_active', 'created_at'),
                'rented' => function ($query) {
                    $query->whereIn('status', self::CURRENT_RENTAL_STATUSES)
                        ->whereHas('stall')
                        ->with([
                            'stall:id,stall_number,section_id',
                            'stall.section:id,name,area_id',
                            'stall.section.area:id,name',
                        ])
                        ->orderByDesc('id');
                },
            ])
            ->orderBy('first_name')
            ->get([
                'id',
                'first_name',
                'middle_name',
                'last_name',
                'contact_number',
            ]);
    }

    public function generate(VendorDetails $vendor)
    {
        $qrCode = DB::transaction(function () use ($vendor) {
            $lockedVendor = VendorDetails::query()
                ->whereKey($vendor->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $hasCurrentRental = Rented::query()
                ->where('vendor_id', $lockedVendor->id)
                ->whereIn('status', self::CURRENT_RENTAL_STATUSES)
                ->whereHas('stall')
                ->exists();

            if (!$hasCurrentRental) {
                abort(422, 'A QR code can only be generated for a vendor with a current rented stall.');
            }

            $qrCode = VendorQrCode::query()
                ->where('vendor_id', $lockedVendor->id)
                ->first();

            if (!$qrCode) {
                $qrCode = VendorQrCode::create([
                    'vendor_id' => $lockedVendor->id,
                    'qr_token' => Str::random(64),
                    'is_active' => true,
                ]);
            } elseif (!$qrCode->is_active) {
                $qrCode->update([
                    'qr_token' => Str::random(64),
                    'is_active' => true,
                ]);
            }

            return $qrCode->load([
                'vendor:id,first_name,middle_name,last_name,contact_number',
            ]);
        });

        return response()->json([
            'message' => 'Vendor QR code is ready.',
            'qr_code' => $qrCode,
        ]);
    }

    public function scan(string $token)
    {
        $qrCode = VendorQrCode::query()
            ->where('qr_token', $token)
            ->where('is_active', true)
            ->with([
                'vendor:id,first_name,middle_name,last_name,contact_number',
                'vendor.rented' => function ($query) {
                    $query->whereIn('status', self::CURRENT_RENTAL_STATUSES)
                        ->whereHas('stall')
                        ->with([
                            'stall:id,stall_number,section_id',
                            'stall.section:id,name,area_id',
                            'stall.section.area:id,name',
                        ])
                        ->orderByDesc('id');
                },
            ])
            ->first();

        if (!$qrCode || !$qrCode->vendor || $qrCode->vendor->rented->isEmpty()) {
            abort(404, 'This vendor QR code is invalid or has no current rented stalls.');
        }

        return response()->json([
            'vendor' => $qrCode->vendor,
            'rentals' => $qrCode->vendor->rented,
        ]);
    }
}
