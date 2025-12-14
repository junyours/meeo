<?php

use Illuminate\Http\Request;
use App\Models\MeatInspector;

// Controllers
use App\Models\InchargeCollector;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AreaController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\StallController;
use App\Http\Controllers\TargetController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\AnimalsController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\RemittanceController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\WharfPaymentController;
use App\Http\Controllers\MainCollectorController;
use App\Http\Controllers\MeatInspectorController;
use App\Http\Controllers\MotopoolPaymentController;
use App\Http\Controllers\SlaughterPaymentController;
use App\Http\Controllers\InchargeCollectorController;
use App\Http\Controllers\MarketRegistrationController;
use App\Http\Controllers\ReportsController;
use App\Models\Customer;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| Grouped by controller for clarity
*/

// 🔑 AuthController (Login / Register / Logout)
Route::post('/register', [LoginController::class, 'register']);
Route::post('/create_account', [LoginController::class, 'AdminCreateAccount']);
Route::post('/login', [LoginController::class, 'login']);
Route::middleware('auth:sanctum')->post('/logout', [LoginController::class, 'logout']);

// 🏘️ SectionController
Route::get('/sections', [SectionController::class, 'index']);
Route::post('/sections', [SectionController::class, 'store']);
Route::put('/sections/{id}', [SectionController::class, 'update']);
Route::delete('/sections/{id}', [SectionController::class, 'destroy']);

// 🏬 StallController
Route::get('/stalls', [StallController::class, 'index']);
Route::post('/stalls', [StallController::class, 'store']);
Route::post('/addstall', [StallController::class, 'addstall']);
Route::put('/stalls/{id}', [StallController::class, 'update']);
Route::delete('/stalls/{id}', [StallController::class, 'destroy']);
Route::middleware('auth:sanctum')->get('/collector/stalls', [StallController::class, 'stallsForCollector']);

Route::put('/sections/{id}', [SectionController::class, 'update']);
Route::put('/stalls/{id}', [StallController::class, 'update']);
// 🌍 AreaController
Route::put('/stall/{stall}/toggle-active', [StallController::class, 'toggleActive']);
Route::get('/stall/{stall}/status-logs', [StallController::class, 'statusLogs']);

Route::get('/areas', [AreaController::class, 'index']);
Route::post('/areas', [AreaController::class, 'store']);
Route::put('/areas/{id}', [AreaController::class, 'update']);
Route::delete('/areas/{id}', [AreaController::class, 'destroy']);


Route::middleware('auth:sanctum')->get('/stall-report', [ReportsController::class, 'index']);
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/targets', [TargetController::class, 'store']);
    Route::put('/targets/{id}', [TargetController::class, 'update']);
    Route::get('/targets', [TargetController::class, 'report']);
    Route::get('/vendor/pending-payments', [VendorController::class, 'pendingPayments']);
Route::post('/vendor/confirm-payment/{id}', [VendorController::class, 'confirmPayment']);

});
Route::get('/rented/blocklisted', [VendorController::class, 'blocklisted']);

Route::middleware('auth:sanctum')->get('/vendor/rented-history', [VendorController::class, 'getVendorRentedHistory']);


Route::prefix('admin')->group(function () {
    Route::get('/stall-removal-requests', [AdminController::class, 'getRemovalRequests']);
   Route::post('/stall-removal-requests/{id}/approve', [AdminController::class, 'approveRemoval']);
Route::post('/stall-removal-requests/{id}/reject', [AdminController::class, 'rejectRemoval']);
});

// 👨‍💼 VendorController
Route::post('/vendor/remove-stall/{rentedId}', [VendorController::class, 'removeStall']);

Route::middleware('auth:sanctum')->get('/vendor/rented-stalls', [VendorController::class, 'getRentedStallsForVendor']);
Route::middleware('auth:sanctum')->get('/vendors', [VendorController::class, 'vendor']);
Route::middleware('auth:sanctum')->get('/admin/notifications', [AdminController::class, 'getAdminNotifications']);
Route::post('/admin/notifications/{id}/read', [AdminController::class, 'markAsReadNotification']);
Route::middleware('auth:sanctum')->get('/vendor/payment-history', [VendorController::class, 'getVendorPaymentHistory']);
Route::middleware('auth:sanctum')->post('/vendor-details', [VendorController::class, 'store']);
Route::middleware('auth:sanctum')->get('/vendor-details', [VendorController::class, 'show']);
Route::middleware('auth:sanctum')->get('/incharge/dashboard-stats', [PaymentController::class, 'stats']);

Route::middleware('auth:sanctum')->post('/vendor/pay-advance', [VendorController::class, 'payAdvance']);
Route::middleware('auth:sanctum')->get('/collector/pending-advance', [VendorController::class, 'pendingAdvancePayments']);
Route::middleware('auth:sanctum')->post('/collector/collect-advance/{paymentId}', [VendorController::class, 'collectAdvancePayment']);
// routes/api.php
Route::post('/market-registration/{id}/generate-pdf', [MarketRegistrationController::class, 'generatePDF']);


// 👑 Controller
Route::get('/roles', [AdminController::class, 'getRoles']);
Route::middleware('auth:sanctum')->get('/admin/vendor-profiles', [AdminController::class, 'listVendorProfiles']);
Route::middleware('auth:sanctum')->post('/admin/vendor-profiles/{id}/validate', [AdminController::class, 'validateVendor']);
Route::middleware('auth:sanctum')->get('/dashboard-stats', [AdminController::class, 'display']);

// 💵 PaymentController
Route::middleware('auth:sanctum')->get('/market-unremitted', [PaymentController::class, 'MarketunremittedPayments']);
Route::get('/market-collection-details', [PaymentController::class, 'collectionDetails']);
Route::middleware('auth:sanctum')->get('/market-collection-summary', [PaymentController::class, 'collectionSummary']);
Route::middleware('auth:sanctum')->get('/collector/pending-remit-notification', [InchargeCollectorController::class, 'collectorPendingRemitNotification']);
Route::middleware('auth:sanctum')->get('/market-remittance-report/{period}', [PaymentController::class, 'marketRemittanceReport']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/market-registration/{id}/renew', [MarketRegistrationController::class, 'renew']);
});
// 📝 ApplicationController
Route::middleware('auth:sanctum')->post('/applications', [ApplicationController::class, 'store']);
Route::middleware('auth:sanctum')->get('/my-applications', [ApplicationController::class, 'myApplications']);
Route::middleware('auth:sanctum')->get('/applications', [ApplicationController::class, 'index']);
Route::middleware('auth:sanctum')->post('/applications/{id}/status', [ApplicationController::class, 'updateStatus']);
Route::middleware('auth:sanctum')->get('/approved', [ApplicationController::class, 'approved']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/applications/{id}/request-stall-change', [ApplicationController::class, 'requestStallChange']);
    Route::get('/stall-change-requests', [ApplicationController::class, 'listStallChangeRequests']);
    Route::put('/stall-change-requests/{id}/status', [ApplicationController::class, 'updateStallChangeStatus']);
});
// 🏪 MarketRegistrationController
Route::get('/collector/rented', [MarketRegistrationController::class, 'rentedList']);
Route::middleware('auth:sanctum')->post('/collectPayment', [MarketRegistrationController::class, 'collectPayment']);
Route::middleware('auth:sanctum')->post('/market-registrations/{applicationId}', [MarketRegistrationController::class, 'issueRegistration']);

Route::get('/admin/detailed-collections', [ReportsController::class, 'detailedCollections']);
Route::get('/admin/collector-totals', [ReportsController::class, 'collectorTotals']);


// 👷 InchargeCollectorController
Route::middleware('auth:sanctum')->get('/incharge-details', [InchargeCollectorController::class, 'show']);
Route::middleware('auth:sanctum')->post('/incharge-details', [InchargeCollectorController::class, 'store']);
Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get('/incharge-profiles', [InchargeCollectorController::class, 'index']);
    Route::patch('/incharge-profiles/{id}/status', [InchargeCollectorController::class, 'updateStatus']);
    Route::patch('/incharge-profiles/{id}/assign', [InchargeCollectorController::class, 'assignArea']);
});
Route::get('/collector/info', [InchargeCollectorController::class, 'collectorInfo'])->middleware('auth:sanctum');


Route::middleware('auth:sanctum')->group(function () {
    Route::post('/customer-details', [CustomerController::class, 'store']);
    Route::get('/all-inspections', [MeatInspectorController::class, 'allInspections']);

    Route::get('/customer-details', [CustomerController::class, 'show']);
    Route::get('/customers', [CustomerController::class, 'index']); // Meat Inspector sees all
Route::put('/customers/status/{id}', [CustomerController::class, 'updateStatus']);// Approve/Decline
});
Route::middleware('auth:sanctum')->get('/inspector-details', [MeatInspectorController::class, 'show']);
Route::middleware('auth:sanctum')->post('/inspector-details', [MeatInspectorController::class, 'store']);
// 👨‍✈️ MainCollectorController
Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get('/main-details', [MainCollectorController::class, 'show']);
    Route::post('/main-details', [MainCollectorController::class, 'store']);
    Route::get('/main-profiles', [MainCollectorController::class, 'index']);
    Route::patch('/main-profiles/{id}/status', [MainCollectorController::class, 'updateStatus']);
    Route::patch('/main-profiles/{id}/assign', [MainCollectorController::class, 'assignArea']);
});
Route::get('/main-collectors/area/{area}', [MainCollectorController::class, 'mainCollectorsByArea']);

    Route::middleware('auth:sanctum')->get('/meat-details', [MeatInspectorController::class, 'show']);
    Route::patch('/meat-profiles/{id}/status', [MeatInspectorController::class, 'updateStatus']);
    Route::get('/meat-profiles', [MeatInspectorController::class, 'index']);

// 🔐 Authenticated user (default sanctum)
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
// routes/api.php
Route::middleware('auth:sanctum')->get('/my-market-registration', [MarketRegistrationController::class, 'myMarketRegistration']);


Route::get('/market-registration/renewals', [MarketRegistrationController::class, 'getRenewalRequests']);
Route::post('/market-registration/{id}/renewal/{action}', [MarketRegistrationController::class, 'handleRenewalAction']);
// routes/api.php
Route::get('/market-registrations/{applicationId}', [MarketRegistrationController::class, 'viewRegistration']);


Route::middleware('auth:sanctum')->get('/remittance-history', [RemittanceController::class, 'markethistory']);
Route::middleware('auth:sanctum')->post('/remit-collection', [RemittanceController::class, 'store']);

Route::put('/remittance/{id}/decline', [RemittanceController::class, 'decline']);

Route::get('/slaughter/details/{id}', [RemittanceController::class, 'slaughterDetails']);
Route::get('/market/details/{id}', [RemittanceController::class, 'marketDetails']);
Route::get('/motorpool/details/{id}', [RemittanceController::class, 'motorPoolDetails']);
Route::get('/wharf/details/{id}', [RemittanceController::class, 'wharfDetails']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/remittance/pending', [RemittanceController::class, 'pending']);
    Route::get('/remittance/all', [RemittanceController::class, 'allRemittances']);
Route::get('/main-collector/reports', [RemittanceController::class, 'index']);
    Route::post('/remittance/{id}/approve', [RemittanceController::class, 'approve']);
    Route::post('/remittance/{id}/decline', [RemittanceController::class, 'decline']);
});

Route::get('/reports/combined', [ReportsController::class, 'DepartmentsReport']);

Route::middleware('auth:sanctum')->get('/slaughter-already-remitted', [InchargeCollectorController::class, 'alreadyRemittedToday']);

Route::middleware('auth:sanctum')->get('/wharf-already-remitted', [WharfPaymentController::class, 'alreadyRemittedToday']);
Route::middleware('auth:sanctum')->get('/collector/already-remitted-today', [InchargeCollectorController::class, 'alreadyRemittedMarket']);

Route::middleware('auth:sanctum')->get('/motorpool-already-remitted', [MotopoolPaymentController::class, 'alreadyRemittedToday']);


Route::middleware('auth:sanctum')->group(function () {
       Route::get('/unremitted/slaughter', [SlaughterPaymentController::class, 'getUnremittedPayments']);
   Route::get('/slaughter-payments/unremitted', [SlaughterPaymentController::class, 'checkUnremitted']);
     Route::get('/customer/dashboard-metrics', [CustomerController::class, 'metrics']);

    Route::get('/inspector-payment-report/{period}', [SlaughterPaymentController::class, 'inspectorPaymentReport']);
});
Route::get('/inspector-analytics', [SlaughterPaymentController::class, 'getAnalytics']);
Route::get('/customer-payments/{customer}', [SlaughterPaymentController::class, 'getCustomerPayments']);

Route::get('/slaughter-audit', [MeatInspectorController::class, 'auditHistory']);

Route::middleware('auth:sanctum')->post('/slaughter-payments/{id}/collect', [SlaughterPaymentController::class, 'collect']);
Route::middleware('auth:sanctum')->get('/slaughter-payments', [SlaughterPaymentController::class, 'pendingCollections']);
Route::middleware('auth:sanctum')->post('/slaughter-payments', [SlaughterPaymentController::class, 'store']);
Route::get('/slaughter-payments/{id}', [SlaughterPaymentController::class, 'show']);
Route::delete('/slaughter-payments/{id}', [SlaughterPaymentController::class, 'destroy']);
Route::middleware('auth:sanctum')->get('/slaughter-collection-summary', [SlaughterPaymentController::class, 'slaughterCollectionSummary']);
Route::middleware('auth:sanctum')->get('/slaughter-remittance-report/{period}', [SlaughterPaymentController::class, 'slaughterRemittanceReport']);
Route::get('/slaughter-pending', [SlaughterPaymentController::class, 'index']);
    Route::middleware('auth:sanctum')->get('/inspections', [MeatInspectorController::class, 'displayinspection']); // List inspections (today or filtered by date)
    Route::middleware('auth:sanctum')->post('/inspections', [MeatInspectorController::class, 'storedInspection']); // Add new inspection
 Route::middleware('auth:sanctum')->get('/inspectable-animals', [MeatInspectorController::class, 'getInspectableAnimals']);
  Route::middleware('auth:sanctum')->get('/healthy-ante-mortem-animals', [MeatInspectorController::class, 'healthyAnteMortemAnimals']);
Route::get('/customer/{customerId}/todays-animals', [MeatInspectorController::class, 'getTodaysAnimalsByCustomer']);

Route::get('/animals', [AnimalsController::class, 'index']);      // List all animals
Route::get('/animals/{id}', [AnimalsController::class, 'show']);  // Show one animal
Route::post('/animals', [AnimalsController::class, 'store']);     // Create animal
Route::put('/animals/{id}', [AnimalsController::class, 'update']); // Update animal
Route::delete('/animals/{id}', [AnimalsController::class, 'destroy']); // Delete animal

Route::post('/stall/{stall}/remove-vendor', [StallController::class, 'removeVendor']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/admin/unremitted-payments', [PaymentController::class, 'UnremittedPayments']);
Route::get('/remittance/dashboard', [MainCollectorController::class, 'MainCollectorReport']);
Route::get('/remittance-details/{vendorId}/{paymentType}/{paymentDate}', [AdminController::class, 'DisplayDetails']);
Route::middleware('auth:sanctum')->get('/remittance/collectors', [MainCollectorController::class, 'collectors']);
      Route::get('/sidebar-data', [AdminController::class, 'sidebarData']);
Route::get('/slaughter-report', [AdminController::class, 'slaughterReport']);
   Route::post('/notify-customer/{inspection}', [MeatInspectorController::class, 'notifyCustomer']);

Route::get('/reports/market',  [AdminController::class, 'marketReport']);
Route::get('/slaughter-remittance', [AdminController::class, 'slaughterRemittance']);


Route::get('/market/reports',  [AdminController::class, 'MarketRemittance']);

Route::get('/remittance-details/{vendorId}', [AdminController::class, 'getRemittanceDetails']);


Route::middleware('auth:sanctum')->group(function () {
    // Route in api.php
Route::get('/already-remitted-today', [InchargeCollectorController::class, 'alreadyRemittedToday']);

    Route::get('/customer/notifications', [CustomerController::class, 'notification']);
    Route::post('/customer/notifications/{id}/read', [CustomerController::class, 'markAsRead']);
});
        Route::get('/vendors/missed-payments', [AdminController::class, 'vendorsWithMissedPayments']);
Route::post('/admin/notify-vendor', [AdminController::class, 'notifyVendor']);
Route::get('/stall/{id}/history', [AdminController::class, 'stallHistory']);
Route::get('/stall/{id}', [StallController::class, 'getTenantHistory']);
Route::get('/rented/{id}/payments', [VendorController::class, 'getPayments']);

Route::get('/stall/{id}/tenant', [StallController::class, 'getTenant']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/vendor/notifications', [AdminController::class, 'vendornotification']);
        Route::post('/collector/mark-notification-read/{id}', [InchargeCollectorController::class, 'markAsReadIncharge']);
    Route::post('/vendor/notifications/{id}/read', [AdminController::class, 'markAsRead']);
});

Route::middleware('auth:sanctum')->get('/customer/payments', [CustomerController::class, 'paymentHistory']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/wharf-collection-summary', [WharfPaymentController::class, 'wharfCollectionSummary']);
    Route::get('/wharf-remittance-report/{period}', [WharfPaymentController::class, 'wharfRemittanceReport']);
    Route::post('/wharf-payments', [WharfPaymentController::class, 'store']);
    Route::get('/wharf-details/{id}', [WharfPaymentController::class, 'show']);
    Route::get('/reports/wharf', [WharfPaymentController::class, 'wharfReport']);
    Route::get('/remittance/wharf', [WharfPaymentController::class, 'wharfRemittance']);

    Route::get('/wharf-remittance-history', [WharfPaymentController::class, 'history']);
});

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/motorpool-collection-summary', [MotopoolPaymentController::class, 'motorPoolCollectionSummary']);
    Route::get('/motorpool-remittance-report/{period}', [MotopoolPaymentController::class, 'motorPoolRemittanceReport']);
    Route::post('/motorpool-payments', [MotopoolPaymentController::class, 'store']);
    Route::get('/motorpool-details/{id}', [MotopoolPaymentController::class, 'show']);
    Route::get('/reports/motorpool', [MotopoolPaymentController::class, 'motorPoolReport']);
    Route::get('/motorpool-remittance-history', [MotopoolPaymentController::class, 'history']);
});