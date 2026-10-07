<?php

use App\Http\Controllers\ActivitySalesReportController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\Admin\CollectorAccountController;
use App\Http\Controllers\AdminProfileController;
use App\Http\Controllers\AvailableStallController;
use App\Http\Controllers\Api\AvailableProductsController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\MarketProductController;
use App\Http\Controllers\AreaController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CashTicketTypeController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\DepartmentCollectionController;
use App\Http\Controllers\EventActivityController;
use App\Http\Controllers\EventPaymentController;
use App\Http\Controllers\EventSalesController;
use App\Http\Controllers\EventStallController;
use App\Http\Controllers\EventVendorController;
use App\Http\Controllers\MarketLayoutController;
use App\Http\Controllers\MarketOpenSpaceController;
use App\Http\Controllers\OfficeActivitiesController;
use App\Http\Controllers\PaymentManagementController;
use App\Http\Controllers\PaymentMonitoringController;
use App\Http\Controllers\RentalReportController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\SlaughterhouseCollectionController;
use App\Http\Controllers\StallController;
use App\Http\Controllers\StallRateHistoryController;
use App\Http\Controllers\TargetCollectionController;
use App\Http\Controllers\VendorAnalysisController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\VendorManagementController;
use App\Http\Controllers\VendorQrCodeController;
use App\Http\Controllers\VendorPaymentCalendarController;
use App\Http\Controllers\VendorPaymentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| Grouped by controller for clarity
*/
// Admin Profile Routes
Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get('/profile', [AdminProfileController::class, 'getProfile']);
    Route::post('/send-otp', [AdminProfileController::class, 'sendOTP']);
    Route::post('/verify-otp', [AdminProfileController::class, 'verifyOTP']);   
    Route::put('/profile', [AdminProfileController::class, 'updateProfile']);
  
    // Market & Open Space Collections Routes (moved here for testing)
    Route::prefix('market-open-space')->group(function () {
        Route::get('/collections', [MarketOpenSpaceController::class, 'index']);
        Route::get('/collections-by-year', [MarketOpenSpaceController::class, 'getCollectionsByYear']);
        Route::get('/monthly-details', [MarketOpenSpaceController::class, 'getMonthlyPaymentDetails']);
   
        Route::get('/payment/{paymentId}', [MarketOpenSpaceController::class, 'getPaymentDetails']);
        Route::post('/grouped-payment-details', [MarketOpenSpaceController::class, 'getGroupedPaymentDetails']);
    });
});


// 🔑 AuthController (Login / Register / Logout)
Route::post('/register', [LoginController::class, 'register']);
Route::post('/create_account', [LoginController::class, 'AdminCreateAccount']);
Route::post('/login', [LoginController::class, 'login']);
Route::middleware('auth:sanctum')->post('/logout', [LoginController::class, 'logout']);

Route::middleware('auth:sanctum')->prefix('admin/collector-accounts')->group(function () {
    Route::get('/', [CollectorAccountController::class, 'index']);
    Route::post('/', [CollectorAccountController::class, 'store']);
    Route::post('/{user}/reset-default-password', [CollectorAccountController::class, 'resetDefaultPassword']);
});

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
Route::get('/sections/available-stalls', [SectionController::class, 'availableStalls']);
Route::delete('/sections/{id}', [SectionController::class, 'destroy']);


// 🏬 StallController
Route::get('/stalls', [StallController::class, 'index']);
Route::post('/stalls', [StallController::class, 'store']);
Route::post('/addstall', [StallController::class, 'addstall']);
Route::put('/stalls/{id}', [StallController::class, 'update']);
Route::delete('/stalls/{id}', [StallController::class, 'destroy']);

// Route::put('/sections/{id}', [SectionController::class, 'update']);
Route::put('/stalls/{id}', [StallController::class, 'update']);
// 🌍 AreaController
Route::put('/stall/{stall}/toggle-active', [StallController::class, 'toggleActive']);
Route::get('/stall/{stall}/status-logs', [StallController::class, 'statusLogs']);
Route::put('/stall/{stall}/rent', [StallController::class, 'updateStallRent']);

Route::get('/areas', [AreaController::class, 'index']);
Route::post('/areas', [AreaController::class, 'store']);
Route::put('/areas/{id}', [AreaController::class, 'update']);
Route::delete('/areas/{id}', [AreaController::class, 'destroy']);






Route::prefix('admin')->group(function () {
 
   Route::post('/rented/{id}/pay-missed', [AdminController::class, 'payMissedForRented']);
});

Route::middleware('auth:sanctum')->get('/vendors', [VendorController::class, 'vendor']);
Route::middleware('auth:sanctum')->get('/admin/notifications', [AdminController::class, 'getAdminNotifications']);
Route::post('/admin/notifications/{id}/read', [AdminController::class, 'markAsReadNotification']);

// Payment Management Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/payments', [PaymentManagementController::class, 'index']);
    Route::put('/payments/{id}', [PaymentManagementController::class, 'update']);
    Route::delete('/payments/{id}', [PaymentManagementController::class, 'destroy']);
    Route::get('/vendors', [PaymentManagementController::class, 'getVendors']);
    Route::get('/payment-management/stats', [PaymentManagementController::class, 'getStats']);
    Route::get('/vendors/{vendorId}/payments', [PaymentManagementController::class, 'getVendorPayments']);
});


Route::post('/stall/{stall}/remove-vendor', [StallController::class, 'removeVendor']);

Route::middleware('auth:sanctum')->group(function () {
Route::get('/stall/{id}', [StallController::class, 'getTenantHistory']);
Route::get('/rented/{rentedId}/balance-at-date', [StallController::class, 'getRentalBalanceAtDate']);
Route::post('/rented/{rentedId}/settle-unoccupied-balance', [StallController::class, 'settleUnoccupiedBalance']);
Route::post('/rented/settle-unoccupied-balances', [StallController::class, 'settleUnoccupiedBalances']);
Route::get('/rented/{id}/payments', [VendorController::class, 'getPayments']);
Route::get('/stall/{id}/tenant', [StallController::class, 'getTenant']);
});

Route::middleware('auth:sanctum')->prefix('market-layout')->group(function () {
    Route::get('/', [MarketLayoutController::class, 'index']);
    Route::post('/areas', [MarketLayoutController::class, 'storeArea']);
    Route::put('/areas/{area}', [MarketLayoutController::class, 'updateArea']);
    Route::delete('/areas/{area}', [MarketLayoutController::class, 'destroyArea']);
    Route::post('/areas/reorder', [MarketLayoutController::class, 'reorderAreas']);
    
    Route::post('/sections', [MarketLayoutController::class, 'storeSection']);
    Route::put('/sections/{section}', [MarketLayoutController::class, 'updateSection']);
    Route::delete('/sections/{section}', [MarketLayoutController::class, 'destroySection']);
   
    Route::post('/stalls', [MarketLayoutController::class, 'storeStall']);
    Route::put('/stalls/{stall}', [MarketLayoutController::class, 'updateStall']);
    Route::delete('/stalls/{stall}', [MarketLayoutController::class, 'destroyStall']);
  
    // Stall Vendor Assignment Routes
    Route::post('/stalls/{stall}/assign-vendor', [MarketLayoutController::class, 'assignVendorToStall']);

    
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

});

Route::middleware('auth:sanctum')->prefix('vendor-qr-codes')->group(function () {
    Route::get('/', [VendorQrCodeController::class, 'index']);
    Route::post('/{vendor}/generate', [VendorQrCodeController::class, 'generate']);
    Route::get('/scan/{token}', [VendorQrCodeController::class, 'scan']);
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

// 📊 Department target and collection reports
Route::middleware('auth:sanctum')->prefix('department-collection')->group(function () {
    Route::get('/', [DepartmentCollectionController::class, 'index']);
    Route::get('/departments', [DepartmentCollectionController::class, 'getDepartments']);
    Route::post('/departments', [DepartmentCollectionController::class, 'storeDepartment']);
    Route::put('/departments/{department}', [DepartmentCollectionController::class, 'updateDepartment']);
    Route::delete('/departments/{department}', [DepartmentCollectionController::class, 'destroyDepartment']);
    Route::post('/targets', [DepartmentCollectionController::class, 'storeTarget']);
    Route::put('/departments/{department}/collections', [DepartmentCollectionController::class, 'updateMonthlyCollection']);
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

// 🐄 Slaughterhouse Collection Management Routes
Route::middleware('auth:sanctum')->prefix('slaughterhouse')->group(function () {
    Route::get('/collections', [SlaughterhouseCollectionController::class, 'index']);
    Route::get('/collections/monthly-summary', [SlaughterhouseCollectionController::class, 'monthlySummary']);
    Route::get('/collections/daily-details', [SlaughterhouseCollectionController::class, 'dailyDetails']);
    Route::get('/collections/daily-summary', [SlaughterhouseCollectionController::class, 'dailySummary']);
    Route::get('/collections/{id}', [SlaughterhouseCollectionController::class, 'show']);
    Route::post('/collections', [SlaughterhouseCollectionController::class, 'store']);
    Route::put('/collections/{id}', [SlaughterhouseCollectionController::class, 'update']);
    Route::delete('/collections/{id}', [SlaughterhouseCollectionController::class, 'destroy']);
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

// Rental report routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/reports/rental-report', [RentalReportController::class, 'rentalReport']);
    Route::get('/reports/vendor-details', [RentalReportController::class, 'vendorDetails']);
    Route::put('/rented/{id}/update-rented-at', [RentalReportController::class, 'updateRentedAt']);
    Route::delete('/rented/{id}/delete-record', [RentalReportController::class, 'deleteRecord']);
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
    Route::get('/vendor/{vendorId}/payment-details', [VendorAnalysisController::class, 'getVendorPaymentDetails']);
    Route::get('/vendor/{vendorId}', [VendorAnalysisController::class, 'getVendorAnalysis']);
    Route::post('/update-or-numbers', [VendorAnalysisController::class, 'updateOrNumbersForDate']);
    Route::get('/get-or-numbers', [VendorAnalysisController::class, 'getOrNumbersForDate']);
    Route::get('/all-vendors-with-balances', [VendorAnalysisController::class, 'getAllVendorsWithBalances']);
});



// 📈 Stall Rate History Routes
Route::prefix('stall-rate-history')->group(function () {
    Route::get('/stall/{stallId}/recent', [StallRateHistoryController::class, 'getRecentStallRateChanges']);

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
    Route::get('/{id}/price-history', [MarketProductController::class, 'priceHistory']);
Route::get('/{id}/price-history/date-range', [MarketProductController::class, 'priceHistoryByDateRange']);
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
    Route::get('/available-stalls', [AvailableStallController::class, 'index']);
    Route::get('/product-catalog', [AvailableProductsController::class, 'getCatalog']);
    Route::get('/price-history', [AvailableProductsController::class, 'getPriceHistory']);
    Route::get('/categories', [AvailableProductsController::class, 'getCategories']);
    Route::get('/products', [AvailableProductsController::class, 'getAllProducts']);
    Route::get('/products/available', [AvailableProductsController::class, 'getAvailableProducts']);
    Route::get('/market-fees', [SectionController::class, 'marketFees']);
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
    Route::get('/existing-dates', [EventPaymentController::class, 'getExistingPaymentDates']);
    Route::get('/activity/{activityId}', [EventPaymentController::class, 'getActivityPayments']);
    Route::get('/stall/{stallId}', [EventPaymentController::class, 'getStallPayments']);
    Route::get('/vendor/{vendorId}', [EventPaymentController::class, 'getVendorPayments']);
    Route::get('/summary', [EventPaymentController::class, 'getPaymentSummary']);
    Route::get('/vendors/{activityId}', [EventPaymentController::class, 'getVendorsByActivity']);
    Route::get('/stalls/{activityId}/{vendorId}', [EventPaymentController::class, 'getStallsByVendorAndActivity']);
    Route::get('/{id}', [EventPaymentController::class, 'show']);
    Route::put('/{id}', [EventPaymentController::class, 'update']);
    Route::delete('/{id}', [EventPaymentController::class, 'destroy']);
});

Route::middleware('auth:sanctum')->prefix('event-sales')->group(function () {
    Route::get('/activity/{activityId}', [EventSalesController::class, 'getActivitySalesReport']);
    Route::post('/reports', [EventSalesController::class, 'storeSalesReport']);
    Route::get('/reports/{id}', [EventSalesController::class, 'showSalesReport']);
    Route::put('/reports/{id}', [EventSalesController::class, 'updateSalesReport']);
    Route::delete('/reports/{id}', [EventSalesController::class, 'destroySalesReport']);
    Route::get('/stall/{stallId}/history', [EventSalesController::class, 'getStallSalesHistory']);
    Route::get('/vendor/{activityId}/{vendorId}/report-dates', [EventSalesController::class, 'getVendorReportDates']);
    Route::delete('/reports/by-day', [EventSalesController::class, 'deleteSalesReportByDay']);
    Route::post('/reports/by-day', [EventSalesController::class, 'deleteSalesReportByDay']);
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
    Route::get('/activity/{activityId}', [EventVendorController::class, 'getVendorsByActivity']);
});

Route::prefix('office-activities')->group(function () {

    Route::get('/', [
        OfficeActivitiesController::class,
        'index'
    ]);

    Route::post('/', [
        OfficeActivitiesController::class,
        'store'
    ]);

    Route::get('/{id}', [
        OfficeActivitiesController::class,
        'show'
    ]);

    Route::put('/{id}', [
        OfficeActivitiesController::class,
        'update'
    ]);

    Route::delete('/{id}', [
        OfficeActivitiesController::class,
        'destroy'
    ]);

    Route::delete('/image/{id}', [
        OfficeActivitiesController::class,
        'destroyImage'
    ]);
});