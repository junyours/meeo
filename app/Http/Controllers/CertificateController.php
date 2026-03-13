<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\VendorDetails;
use App\Models\Stalls;
use App\Models\AdminActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CertificateController extends Controller
{
    public function index(Request $request)
    {
        $query = Certificate::with(['vendor', 'stall.section.area', 'issuedBy'])
                           ->orderBy('created_at', 'desc');

        if ($request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('certificate_number', 'like', "%{$search}%")
                  ->orWhere('vendor_first_name', 'like', "%{$search}%")
                  ->orWhere('vendor_last_name', 'like', "%{$search}%")
                  ->orWhere('stall_number', 'like', "%{$search}%");
            });
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->vendor_id) {
            $query->where('vendor_id', $request->vendor_id);
        }

        return response()->json($query->paginate($request->per_page ?? 15));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'template_name' => 'required|string|max:255',
            'template_fields' => 'nullable|array',
            'vendor_first_name' => 'required|string|max:255',
            'vendor_middle_name' => 'nullable|string|max:255',
            'vendor_last_name' => 'required|string|max:255',
            'stall_number' => 'nullable|string|max:255',
            'issue_date' => 'required|date',
            'expiry_date' => 'required|date|after:issue_date',
            'notes' => 'nullable|string|max:1000',
            'vendor_id' => 'nullable|exists:vendor_details,id',
            'stall_id' => 'nullable|exists:stall,id',
        ]);

        $validated['certificate_number'] = $this->generateCertificateNumber();
        $validated['issued_by'] = auth()->id();

        $certificate = Certificate::create($validated);
        
        AdminActivity::log(
            auth()->id(),
            'create',
            'certificate',
            "Issued certificate: {$certificate->certificate_number}",
            null,
            $certificate->toArray()
        );

        return response()->json($certificate->load(['vendor', 'stall', 'issuedBy']), 201);
    }

    public function show(Certificate $certificate)
    {
        return response()->json($certificate->load(['vendor', 'stall.section.area', 'issuedBy']));
    }

    public function update(Request $request, Certificate $certificate)
    {
        $validated = $request->validate([
            'template_name' => 'required|string|max:255',
            'template_fields' => 'nullable|array',
            'vendor_first_name' => 'required|string|max:255',
            'vendor_middle_name' => 'nullable|string|max:255',
            'vendor_last_name' => 'required|string|max:255',
            'stall_number' => 'nullable|string|max:255',
            'issue_date' => 'required|date',
            'expiry_date' => 'required|date|after:issue_date',
            'notes' => 'nullable|string|max:1000',
            'status' => 'required|in:active,expired,revoked',
            'vendor_id' => 'nullable|exists:vendor_details,id',
            'stall_id' => 'nullable|exists:stall,id',
        ]);

        $oldValues = $certificate->toArray();
        $certificate->update($validated);
        
        AdminActivity::log(
            auth()->id(),
            'update',
            'certificate',
            "Updated certificate: {$certificate->certificate_number}",
            $oldValues,
            $certificate->toArray()
        );

        return response()->json($certificate->load(['vendor', 'stall', 'issuedBy']));
    }

    public function destroy(Certificate $certificate)
    {
        $certificateNumber = $certificate->certificate_number;
        $certificate->delete();
        
        AdminActivity::log(
            auth()->id(),
            'delete',
            'certificate',
            "Deleted certificate: {$certificateNumber}",
            $certificate->toArray(),
            null
        );

        return response()->json(null, 204);
    }

    public function renew(Request $request, Certificate $certificate)
    {
        $validated = $request->validate([
            'new_expiry_date' => 'required|date|after:today',
            'renewal_notes' => 'nullable|string|max:1000',
        ]);

        $oldValues = [
            'old_expiry_date' => $certificate->expiry_date,
            'old_status' => $certificate->status,
        ];

        $certificate->update([
            'expiry_date' => $validated['new_expiry_date'],
            'status' => 'active',
            'notes' => $validated['renewal_notes'] ?? null,
        ]);
        
        AdminActivity::log(
            auth()->id(),
            'renew',
            'certificate',
            "Renewed certificate: {$certificate->certificate_number}",
            $oldValues,
            [
                'new_expiry_date' => $validated['new_expiry_date'],
                'new_status' => 'active',
            ]
        );

        return response()->json($certificate->load(['vendor', 'stall', 'issuedBy']));
    }

    public function revoke(Request $request, Certificate $certificate)
    {
        $validated = $request->validate([
            'revocation_reason' => 'required|string|max:1000',
        ]);

        $oldValues = [
            'old_status' => $certificate->status,
        ];

        $certificate->update([
            'status' => 'revoked',
            'notes' => $validated['revocation_reason'],
        ]);
        
        AdminActivity::log(
            auth()->id(),
            'revoke',
            'certificate',
            "Revoked certificate: {$certificate->certificate_number}",
            $oldValues,
            [
                'new_status' => 'revoked',
                'revocation_reason' => $validated['revocation_reason'],
            ]
        );

        return response()->json($certificate->load(['vendor', 'stall', 'issuedBy']));
    }

    public function getVendorCertificates(VendorDetails $vendor)
    {
        $certificates = Certificate::with(['stall.section.area', 'issuedBy'])
                                 ->where('vendor_id', $vendor->id)
                                 ->orderBy('created_at', 'desc')
                                 ->get();

        return response()->json($certificates);
    }

    public function getExpiringSoon(Request $request)
    {
        $validated = $request->validate([
            'days' => 'nullable|integer|min:1|max:365',
        ]);

        $days = $validated['days'] ?? 30;

        $certificates = Certificate::with(['vendor', 'stall.section.area', 'issuedBy'])
                                 ->expiringSoon($days)
                                 ->orderBy('expiry_date')
                                 ->get();

        return response()->json($certificates);
    }

    public function getExpired()
    {
        $certificates = Certificate::with(['vendor', 'stall.section.area', 'issuedBy'])
                                 ->expired()
                                 ->orderBy('expiry_date', 'desc')
                                 ->get();

        return response()->json($certificates);
    }

    public function getTemplates()
    {
        $templates = [
            [
                'name' => 'Market Stall Certificate',
                'fields' => [
                    'first_name' => 'text',
                    'middle_name' => 'text',
                    'last_name' => 'text',
                    'stall_number' => 'text',
                    'issue_date' => 'date',
                    'expiry_date' => 'date',
                ],
            ],
            [
                'name' => 'Business Permit Certificate',
                'fields' => [
                    'first_name' => 'text',
                    'middle_name' => 'text',
                    'last_name' => 'text',
                    'business_name' => 'text',
                    'issue_date' => 'date',
                    'expiry_date' => 'date',
                ],
            ],
            [
                'name' => 'Health Certificate',
                'fields' => [
                    'first_name' => 'text',
                    'middle_name' => 'text',
                    'last_name' => 'text',
                    'stall_number' => 'text',
                    'health_status' => 'text',
                    'issue_date' => 'date',
                    'expiry_date' => 'date',
                ],
            ],
        ];

        return response()->json($templates);
    }

    public function generatePdf(Certificate $certificate)
    {
        // This would generate a PDF certificate
        // For now, return the certificate data that can be used to generate PDF on frontend
        return response()->json([
            'certificate' => $certificate->load(['vendor', 'stall.section.area', 'issuedBy']),
            'template_data' => [
                'certificate_number' => $certificate->certificate_number,
                'vendor_name' => $certificate->vendor_full_name,
                'stall_number' => $certificate->stall_number,
                'issue_date' => $certificate->issue_date->format('F d, Y'),
                'expiry_date' => $certificate->expiry_date->format('F d, Y'),
                'issued_by' => $certificate->issuedBy->name ?? 'System',
                'template_fields' => $certificate->template_fields,
            ],
        ]);
    }

    private function generateCertificateNumber()
    {
        $prefix = 'CERT';
        $year = date('Y');
        $sequence = Certificate::whereYear('created_at', $year)->count() + 1;
        
        return sprintf('%s-%s-%06d', $prefix, $year, $sequence);
    }
}
