<?php

use App\Http\Controllers\ActivitySalesReportController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminProfileController;
use App\Http\Controllers\AnimalsController;
use App\Http\Controllers\Api\AvailableProductsController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\MarketProductController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\AreaController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CashTicketTypeController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DepartmentCollectionController;
use App\Http\Controllers\EventActivityController;
use App\Http\Controllers\EventPaymentController;
use App\Http\Controllers\EventSalesController;
use App\Http\Controllers\EventStallController;
use App\Http\Controllers\EventVendorController;
use App\Http\Controllers\InchargeCollectorController;
use App\Http\Controllers\MainCollectorController;
use App\Http\Controllers\MarketLayoutController;
use App\Http\Controllers\MarketOpenSpaceController;
use App\Http\Controllers\MarketRegistrationController;
use App\Http\Controllers\MeatInspectorController;
use App\Http\Controllers\MotopoolPaymentController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentManagementController;
use App\Http\Controllers\PaymentMonitoringController;
use App\Http\Controllers\PaymentReportsController;
use App\Http\Controllers\RemittanceController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\SlaughterPaymentController;
use App\Http\Controllers\StallController;
use App\Http\Controllers\StallRateHistoryController;
use App\Http\Controllers\TargetCollectionController;
use App\Http\Controllers\TargetController;
use App\Http\Controllers\VendorAnalysisController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\VendorManagementController;
use App\Http\Controllers\VendorPaymentCalendarController;
use App\Http\Controllers\VendorPaymentController;
use App\Http\Controllers\WharfPaymentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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

// 🔐 Enhanced Authentication Routes
Route::prefix('auth')->group(function () {
    Route::post('/validate-credentials', [LoginController::class, 'validateCredentials']);
    Route::post('/send-otp', [LoginController::class, 'sendOTP']);
    Route::post('/verify-otp', [LoginController::class, 'verifyOTP']);
    Route::get('/captcha', [LoginController::class, 'generateCaptcha']);
    
    // Forgot Password Routes
    Route::post('/check-username', [LoginController::class, 'checkUsername']);
    Route::post('/forgot-password', [LoginController::class, 'forgotPassword']);
    Route::post('/send-reset-otp', [LoginController::class, 'sendResetOTP']);
    Route::post('/verify-reset-otp', [LoginController::class, 'verifyResetOTP']);
    Route::post('/reset-password', [LoginController::class, 'resetPassword']);
});

// 🏘️ SectionController
Route::get('/   ', [SectionController::class, 'index']);
Route::post('/sections', [SectionController::class, 'store']);
Route::put('/sections/{id}', [SectionController::class, 'update']);
Route::delete('/sections/{id}', [SectionController::class, 'destroy']);
Route::get('/sections/available-stalls', [SectionController::class, 'availableStalls']);

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
Route::put('/stall/{stall}/rent', [StallController::class, 'updateStallRent']);

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
   Route::post('/rented/{id}/pay-missed', [AdminController::class, 'payMissedForRented']);
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

// Payment Management Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/payments', [PaymentManagementController::class, 'index']);
    Route::put('/payments/{id}', [PaymentManagementController::class, 'update']);
    Route::delete('/payments/{id}', [PaymentManagementController::class, 'destroy']);
    Route::get('/vendors', [PaymentManagementController::class, 'getVendors']);
    Route::get('/payment-management/stats', [PaymentManagementController::class, 'getStats']);
    Route::get('/vendors/{vendorId}/payments', [PaymentManagementController::class, 'getVendorPayments']);
});

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
Route::get('/reports/rental-report', [ReportsController::class, 'rentalReport']);
Route::get('/reports/vendor-details', [ReportsController::class, 'vendorDetails']);
Route::middleware('auth:sanctum')->put('/rented/{id}/update-rented-at', [ReportsController::class, 'updateRentedAt']);

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

// Payment Reports Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/payments/all', [PaymentReportsController::class, 'getAllPayments']);
    Route::get('/rented/all', [PaymentReportsController::class, 'getAllRentals']);
    Route::get('/payment-reports/stats', [PaymentReportsController::class, 'getPaymentStats']);
    Route::get('/payment-reports/payment/{id}', [PaymentReportsController::class, 'getPaymentDetails']);
    Route::get('/payment-reports/rental/{id}', [PaymentReportsController::class, 'getRentalDetails']);
    Route::get('/payment-reports/payments/filtered', [PaymentReportsController::class, 'getFilteredPayments']);
    Route::get('/payment-reports/rentals/filtered', [PaymentReportsController::class, 'getFilteredRentals']);
    
    // New consolidated detail endpoints
    Route::get('/payments/details/{ids}', [PaymentReportsController::class, 'getPaymentDetails']);
    Route::get('/rentals/details/{ids}', [PaymentReportsController::class, 'getRentalDetails']);
    
    // Market & Open Space Collections
    Route::get('/market-open-space-collections', [PaymentReportsController::class, 'getMarketOpenSpaceCollections']);
});

// Report Routes

// 🏗️ Market Layout Management Routes
Route::middleware('auth:sanctum')->prefix('market-layout')->group(function () {
    Route::get('/', [MarketLayoutController::class, 'index']);
    Route::post('/areas', [MarketLayoutController::class, 'storeArea']);
    Route::put('/areas/{area}', [MarketLayoutController::class, 'updateArea']);
    Route::delete('/areas/{area}', [MarketLayoutController::class, 'destroyArea']);
    Route::post('/areas/reorder', [MarketLayoutController::class, 'reorderAreas']);
    
    Route::post('/sections', [MarketLayoutController::class, 'storeSection']);
    Route::put('/sections/{section}', [MarketLayoutController::class, 'updateSection']);
    Route::delete('/sections/{section}', [MarketLayoutController::class, 'destroySection']);
    Route::post('/sections/reorder', [MarketLayoutController::class, 'reorderSections']);
    
    Route::post('/stalls', [MarketLayoutController::class, 'storeStall']);
    Route::put('/stalls/{stall}', [MarketLayoutController::class, 'updateStall']);
    Route::delete('/stalls/{stall}', [MarketLayoutController::class, 'destroyStall']);
    Route::post('/stalls/reorder', [MarketLayoutController::class, 'reorderStalls']);
    
    // Stall Vendor Assignment Routes
    Route::post('/stalls/{stall}/assign-vendor', [MarketLayoutController::class, 'assignVendorToStall']);
    Route::post('/stalls/{stall}/remove-vendor', [MarketLayoutController::class, 'removeVendorFromStall']);
    
    // Multi-Stall Assignment Routes
    Route::get('/vendors-for-assignment', [MarketLayoutController::class, 'getVendorsForAssignment']);
    Route::get('/vacant-stalls/{sectionId}', [MarketLayoutController::class, 'getVacantStallsBySection']);
    Route::post('/multi-assign-stalls', [MarketLayoutController::class, 'multiAssignStalls']);
    Route::get('/sections-by-area-type', [MarketLayoutController::class, 'getSectionsByAreaType']);
});

// 👤 Vendor Management Routes
Route::middleware('auth:sanctum')->prefix('vendor-management')->group(function () {
    Route::get('/', [VendorManagementController::class, 'index']);
    Route::post('/', [VendorManagementController::class, 'store']);
    Route::get('/{vendor}', [VendorManagementController::class, 'show']);
    Route::put('/{vendor}', [VendorManagementController::class, 'update']);
    Route::delete('/{vendor}', [VendorManagementController::class, 'destroy']);
    
    Route::post('/{vendor}/assign-stall', [VendorManagementController::class, 'assignToStall']);
    Route::post('/{vendor}/remove-from-stall', [VendorManagementController::class, 'removeFromStall']);
    Route::get('/{vendor}/stall-history', [VendorManagementController::class, 'getStallHistory']);
    Route::get('/available-stalls', [VendorManagementController::class, 'getAvailableStalls']);
    Route::post('/create-and-assign', [VendorManagementController::class, 'createAndAssignVendor']);
});

// 💰 Vendor Payment Management Routes
Route::middleware('auth:sanctum')->prefix('vendor-payments')->group(function () {
    Route::get('/', [VendorPaymentController::class, 'index']);
    Route::get('/history/{vendorId}', [VendorPaymentController::class, 'getPaymentHistory']);
    Route::post('/bulk/{vendorId}', [VendorPaymentController::class, 'bulkPayment']);
    Route::post('/selected-months/{vendorId}', [VendorPaymentController::class, 'processSelectedMonthsPayment']);
    Route::post('/consume-deposit/{vendorId}', [VendorPaymentController::class, 'consumeDeposit']);
    Route::get('/market-collection-report', [VendorPaymentController::class, 'getMarketCollectionReport']);
    
    // Test endpoint to verify backend update
   
});

// �💰 Payment Monitoring Routes
Route::middleware('auth:sanctum')->prefix('payment-monitoring')->group(function () {
    Route::get('/monthly-monitoring', [PaymentMonitoringController::class, 'getMonthlyMonitoring']);
    Route::post('/record-payment', [PaymentMonitoringController::class, 'recordPayment']);
    Route::get('/vendor/{vendor}/summary', [PaymentMonitoringController::class, 'getVendorPaymentSummary']);
    Route::get('/missed-days-report', [PaymentMonitoringController::class, 'getMissedDaysReport']);
});

// 📊 Target & Collection Reporting Routes
Route::middleware('auth:sanctum')->prefix('target-collection')->group(function () {
    Route::get('/departments', [TargetCollectionController::class, 'getDepartments']);
    Route::post('/departments', [TargetCollectionController::class, 'storeDepartment']);
    Route::put('/departments/{department}', [TargetCollectionController::class, 'updateDepartment']);
    Route::delete('/departments/{department}', [TargetCollectionController::class, 'destroyDepartment']);
    
    Route::get('/targets', [TargetCollectionController::class, 'getTargets']);
    Route::post('/targets', [TargetCollectionController::class, 'storeTarget']);
    Route::put('/targets/{target}', [TargetCollectionController::class, 'updateTarget']);
    Route::put('/targets/{target}/monthly-collection', [TargetCollectionController::class, 'updateMonthlyCollection']);
    
    Route::get('/report', [TargetCollectionController::class, 'getReport']);
    Route::get('/monthly-report', [TargetCollectionController::class, 'getMonthlyReport']);
});

// 🧾 Certificate Management Routes
Route::middleware('auth:sanctum')->prefix('certificates')->group(function () {
    Route::get('/', [CertificateController::class, 'index']);
    Route::post('/', [CertificateController::class, 'store']);
    Route::get('/{certificate}', [CertificateController::class, 'show']);
    Route::put('/{certificate}', [CertificateController::class, 'update']);
    Route::delete('/{certificate}', [CertificateController::class, 'destroy']);
    
    Route::post('/{certificate}/renew', [CertificateController::class, 'renew']);
    Route::post('/{certificate}/revoke', [CertificateController::class, 'revoke']);
    Route::get('/{certificate}/pdf', [CertificateController::class, 'generatePdf']);
    
    Route::get('/vendor/{vendor}', [CertificateController::class, 'getVendorCertificates']);
    Route::get('/expiring-soon', [CertificateController::class, 'getExpiringSoon']);
    Route::get('/expired', [CertificateController::class, 'getExpired']);
    Route::get('/templates', [CertificateController::class, 'getTemplates']);
});

// � Vendor Payment Calendar Routes
Route::middleware('auth:sanctum')->prefix('vendor-payment-calendar')->group(function () {
    Route::get('/', [VendorPaymentCalendarController::class, 'index']);
    Route::get('/vendor/{vendorId}/date', [VendorPaymentCalendarController::class, 'getVendorPaymentsByDate']);
    Route::get('/stats', [VendorPaymentCalendarController::class, 'getMonthlyStats']);
});

// Dashboard Routes
Route::middleware('auth:sanctum')->prefix('dashboard')->group(function () {
    Route::get('/stats', [AdminController::class, 'display']);
    Route::get('/expected-collection-analysis', [AdminController::class, 'expectedCollectionAnalysis']);
});
Route::middleware('auth:sanctum')->prefix('department-collection')->group(function () {
    Route::get('/', [DepartmentCollectionController::class, 'index']);
    Route::post('/targets', [DepartmentCollectionController::class, 'storeTarget']);
    Route::put('/targets/{targetId}', [DepartmentCollectionController::class, 'destroyTarget']);
    Route::get('/departments/{departmentId}', [DepartmentCollectionController::class, 'show']);
    Route::put('/departments/{departmentId}/collections', [DepartmentCollectionController::class, 'updateMonthlyCollection']);
    Route::put('/departments/{departmentId}/targets', [DepartmentCollectionController::class, 'updateMonthlyTarget']);
    Route::get('/dashboard', [DepartmentCollectionController::class, 'dashboard']);
    Route::post('/bulk-collections', [DepartmentCollectionController::class, 'bulkUpdateCollections']);
    Route::get('/departments', [DepartmentCollectionController::class, 'getDepartments']);
    Route::post('/departments', [DepartmentCollectionController::class, 'storeDepartment']);
});

// 📊 Advanced Reports Routes

// 💰 Cash Ticket Routes

// 📅 Daily Collection Routes

// 💰 Cash Ticket Type Management Routes
Route::middleware('auth:sanctum')->prefix('cash-ticket-types')->group(function () {
    Route::get('/', [CashTicketTypeController::class, 'index']);
    Route::post('/', [CashTicketTypeController::class, 'store']);
    
    // Collections and analytics (must come before /{id} route)
    Route::get('/daily-collections', [CashTicketTypeController::class, 'getDailyCollections']);
    Route::get('/monthly-collections', [CashTicketTypeController::class, 'getMonthlyCollections']);
    Route::post('/save-daily-payments', [CashTicketTypeController::class, 'saveDailyPayments']);
    Route::get('/analytics', [CashTicketTypeController::class, 'getAnalytics']);
    
    // Parameterized routes (must come after specific routes)
    Route::get('/{id}', [CashTicketTypeController::class, 'show']);
    Route::put('/{id}', [CashTicketTypeController::class, 'update']);
    Route::delete('/{id}', [CashTicketTypeController::class, 'destroy']);
});

// Vendor Analysis Routes
Route::prefix('vendor-analysis')->group(function () {
    Route::get('/vendors', [VendorAnalysisController::class, 'getVendors']);
    Route::get('/vendor/{vendorId}', [VendorAnalysisController::class, 'getVendorAnalysis']);
    Route::post('/update-or-numbers', [VendorAnalysisController::class, 'updateOrNumbersForDate']);
    Route::get('/get-or-numbers', [VendorAnalysisController::class, 'getOrNumbersForDate']);
    Route::get('/all-vendors-with-balances', [VendorAnalysisController::class, 'getAllVendorsWithBalances']);
});

// Admin Profile Routes
Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get('/profile', [AdminProfileController::class, 'getProfile']);
    Route::post('/send-otp', [AdminProfileController::class, 'sendOTP']);
    Route::post('/verify-otp', [AdminProfileController::class, 'verifyOTP']);   
    Route::put('/profile', [AdminProfileController::class, 'updateProfile']);
    Route::get('/activity-log', [AdminProfileController::class, 'getActivityLog']);
    
    // Market & Open Space Collections Routes (moved here for testing)
    Route::prefix('market-open-space')->group(function () {
        Route::get('/collections', [MarketOpenSpaceController::class, 'index']);
        Route::get('/collections-by-year', [MarketOpenSpaceController::class, 'getCollectionsByYear']);
        Route::get('/monthly-details', [MarketOpenSpaceController::class, 'getMonthlyPaymentDetails']);
        Route::get('/analytics', [MarketOpenSpaceController::class, 'getAnalytics']);
        Route::get('/payment/{paymentId}', [MarketOpenSpaceController::class, 'getPaymentDetails']);
        Route::post('/grouped-payment-details', [MarketOpenSpaceController::class, 'getGroupedPaymentDetails']);
    });
});

// 📈 Stall Rate History Routes
Route::prefix('stall-rate-history')->group(function () {
    // Get rate history for a specific stall
    Route::get('/stall/{stallId}', [StallRateHistoryController::class, 'getStallRateHistory']);
    
    // Get rate for a specific stall, year, and month
    Route::get('/stall/{stallId}/year/{year}/month/{month}', [StallRateHistoryController::class, 'getRateForMonth']);
    
    // Demonstrate rate calculation with example scenarios
    Route::get('/stall/{stallId}/demonstrate', [StallRateHistoryController::class, 'demonstrateRateCalculation']);
    
    // Get comprehensive dashboard data
    Route::get('/dashboard', [StallRateHistoryController::class, 'getDashboardData']);
    
    // Initialize rate history (admin functions)
    Route::post('/initialize-all', [StallRateHistoryController::class, 'initializeAllStalls']);
    Route::post('/initialize/{stallId}', [StallRateHistoryController::class, 'initializeStall']);
});

// 🛍️ Product Management Routes
Route::middleware('auth:sanctum')->prefix('products')->group(function () {
    Route::get('/', [MarketProductController::class, 'index']);
    Route::post('/', [MarketProductController::class, 'store']);
    Route::get('/{id}', [MarketProductController::class, 'show']);
    Route::put('/{id}', [MarketProductController::class, 'update']);
    Route::delete('/{id}', [MarketProductController::class, 'destroy']);
    Route::get('/category/{categoryId}', [MarketProductController::class, 'getByCategory']);
});

// 📂 Category Management Routes
Route::middleware('auth:sanctum')->prefix('categories')->group(function () {
    Route::get('/', [CategoryController::class, 'index']);
    Route::post('/', [CategoryController::class, 'store']);
    Route::get('/{id}', [CategoryController::class, 'show']);
    Route::put('/{id}', [CategoryController::class, 'update']);
    Route::delete('/{id}', [CategoryController::class, 'destroy']);
});

// 🏪 Public Available Products Routes (No Authentication Required)
Route::prefix('public')->group(function () {
    Route::get('/categories', [AvailableProductsController::class, 'getCategories']);
    Route::get('/products', [AvailableProductsController::class, 'getAllProducts']);
    Route::get('/products/available', [AvailableProductsController::class, 'getAvailableProducts']);
    Route::get('/products/category/{categoryId}', [AvailableProductsController::class, 'getProductsByCategory']);
    Route::get('/products/{id}', [AvailableProductsController::class, 'getProduct']);
});

// 🎉 Event Management Routes
Route::middleware('auth:sanctum')->prefix('event-activities')->group(function () {
    Route::get('/', [EventActivityController::class, 'index']);
    Route::post('/', [EventActivityController::class, 'store']);
    Route::get('/active', [EventActivityController::class, 'getActiveActivities']);
    Route::post('/{activityId}/bulk-create-stalls', [EventActivityController::class, 'bulkCreateStalls']);
    Route::get('/{id}', [EventActivityController::class, 'show']);
    Route::put('/{id}', [EventActivityController::class, 'update']);
    Route::delete('/{id}', [EventActivityController::class, 'destroy']);
    Route::get('/{id}/stats', [EventActivityController::class, 'getActivityStats']);
});

Route::middleware('auth:sanctum')->prefix('event-stalls')->group(function () {
    Route::get('/', [EventStallController::class, 'index']);
    Route::post('/', [EventStallController::class, 'store']);
    Route::get('/{id}', [EventStallController::class, 'show']);
    Route::put('/{id}', [EventStallController::class, 'update']);
    Route::delete('/{id}', [EventStallController::class, 'destroy']);
    Route::post('/{id}/assign-vendor', [EventStallController::class, 'assignVendor']);
    Route::post('/{id}/release', [EventStallController::class, 'releaseStall']);
    Route::get('/available/{activityId}', [EventStallController::class, 'getAvailableStalls']);
});

Route::middleware('auth:sanctum')->prefix('event-payments')->group(function () {
    Route::get('/', [EventPaymentController::class, 'index']);
    Route::post('/', [EventPaymentController::class, 'store']);
    Route::get('/{id}', [EventPaymentController::class, 'show']);
    Route::put('/{id}', [EventPaymentController::class, 'update']);
    Route::delete('/{id}', [EventPaymentController::class, 'destroy']);
    Route::get('/activity/{activityId}', [EventPaymentController::class, 'getActivityPayments']);
    Route::get('/stall/{stallId}', [EventPaymentController::class, 'getStallPayments']);
    Route::get('/vendor/{vendorId}', [EventPaymentController::class, 'getVendorPayments']);
    Route::get('/summary', [EventPaymentController::class, 'getPaymentSummary']);
    Route::get('/vendors/{activityId}', [EventPaymentController::class, 'getVendorsByActivity']);
    Route::get('/stalls/{activityId}/{vendorId}', [EventPaymentController::class, 'getStallsByVendorAndActivity']);
});

Route::middleware('auth:sanctum')->prefix('event-sales')->group(function () {
    Route::get('/activity/{activityId}', [EventSalesController::class, 'getActivitySalesReport']);
    Route::post('/reports', [EventSalesController::class, 'storeSalesReport']);
    Route::get('/reports/{id}', [EventSalesController::class, 'showSalesReport']);
    Route::put('/reports/{id}', [EventSalesController::class, 'updateSalesReport']);
    Route::delete('/reports/{id}', [EventSalesController::class, 'destroySalesReport']);
    Route::get('/stall/{stallId}/history', [EventSalesController::class, 'getStallSalesHistory']);
});

Route::middleware('auth:sanctum')->prefix('activity-sales-reports')->group(function () {
    Route::get('/', [ActivitySalesReportController::class, 'index']);
    Route::post('/', [ActivitySalesReportController::class, 'store']);
    Route::get('/{id}', [ActivitySalesReportController::class, 'show']);
    Route::put('/{id}', [ActivitySalesReportController::class, 'update']);
    Route::delete('/{id}', [ActivitySalesReportController::class, 'destroy']);
    Route::post('/{id}/verify', [ActivitySalesReportController::class, 'verify']);
    Route::post('/{id}/unverify', [ActivitySalesReportController::class, 'unverify']);
    Route::get('/activity/{activityId}', [ActivitySalesReportController::class, 'getActivityReport']);
    Route::get('/stall/{stallId}/history', [ActivitySalesReportController::class, 'getStallSalesHistory']);
});

Route::middleware('auth:sanctum')->prefix('event-vendors')->group(function () {
    Route::get('/', [EventVendorController::class, 'index']);
    Route::post('/', [EventVendorController::class, 'store']);
    Route::get('/{id}', [EventVendorController::class, 'show']);
    Route::put('/{id}', [EventVendorController::class, 'update']);
    Route::delete('/{id}', [EventVendorController::class, 'destroy']);
    Route::patch('/{id}/status', [EventVendorController::class, 'updateStatus']);
    Route::get('/available', [EventVendorController::class, 'getAvailableVendors']);
});
