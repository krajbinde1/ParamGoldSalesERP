<?php

use App\Http\Controllers\Api\AdminEmployeeRouteController;
use App\Http\Controllers\Api\AppNotificationController;
use App\Http\Controllers\Api\AppVersionController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\DealerAccountController;
use App\Http\Controllers\Api\DealerApplicationDocumentController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\Director\DirectorCollectionController;
use App\Http\Controllers\Api\Director\DirectorDashboardController;
use App\Http\Controllers\Api\Director\DirectorDealerVisitController;
use App\Http\Controllers\Api\Director\DirectorFieldVisitController;
use App\Http\Controllers\Api\Director\DirectorOrderController;
use App\Http\Controllers\Api\Director\DirectorOutstandingDealerController;
use App\Http\Controllers\Api\Director\DirectorPaymentRequestController;
use App\Http\Controllers\Api\Director\DirectorProductionBatchController;
use App\Http\Controllers\Api\Director\DirectorRouteTrackingController;
use App\Http\Controllers\Api\Director\DirectorTaDaClaimController;
use App\Http\Controllers\Api\Director\PaymentRequestSupportingDocumentController;
use App\Http\Controllers\Api\EmployeeAuthController;
use App\Http\Controllers\Api\EmployeeCollectionController;
use App\Http\Controllers\Api\EmployeeCreditNoteController;
use App\Http\Controllers\Api\EmployeeDashboardController;
use App\Http\Controllers\Api\EmployeeDealerApplicationController;
use App\Http\Controllers\Api\EmployeeDealerController;
use App\Http\Controllers\Api\EmployeeDealerVisitController;
use App\Http\Controllers\Api\EmployeeFarmerLookupController;
use App\Http\Controllers\Api\EmployeeFieldActivityController;
use App\Http\Controllers\Api\EmployeeOrderController;
use App\Http\Controllers\Api\EmployeeProductController;
use App\Http\Controllers\Api\EmployeeRoutePointController;
use App\Http\Controllers\Api\EmployeeTaDaClaimController;
use App\Http\Controllers\Api\EmployeeTaskController;
use App\Http\Controllers\Api\FieldActivityMasterController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\Manager\ManagerCollectionController;
use App\Http\Controllers\Api\Manager\ManagerCreditNoteController;
use App\Http\Controllers\Api\Manager\ManagerDashboardController;
use App\Http\Controllers\Api\Manager\ManagerDealerApplicationController;
use App\Http\Controllers\Api\Manager\ManagerEmployeePerformanceController;
use App\Http\Controllers\Api\Manager\ManagerFieldActivityController;
use App\Http\Controllers\Api\Manager\ManagerOrderController;
use App\Http\Controllers\Api\Manager\ManagerRouteTrackingController;
use App\Http\Controllers\Api\Manager\ManagerTaDaClaimController;
use App\Http\Controllers\Api\Manager\ManagerTeamActivityController;
use App\Http\Controllers\Api\Manager\ManagerTeamAttendanceController;
use App\Http\Controllers\Api\Production\BomApiController;
use App\Http\Controllers\Api\Production\FinishedGoodsApiController;
use App\Http\Controllers\Api\Production\InventoryDashboardApiController;
use App\Http\Controllers\Api\Production\PackagingMaterialApiController;
use App\Http\Controllers\Api\Production\PackagingMaterialInwardApiController;
use App\Http\Controllers\Api\Production\ProductionBatchApiController;
use App\Http\Controllers\Api\Production\ProductionDashboardController;
use App\Http\Controllers\Api\Production\ProductionOrderController;
use App\Http\Controllers\Api\Production\RawMaterialApiController;
use App\Http\Controllers\Api\Production\RawMaterialInwardApiController;
use App\Http\Controllers\Api\Production\RawMaterialInwardAttachmentController;
use App\Http\Controllers\Api\Production\SemiFinishedMaterialApiController;
use App\Http\Controllers\Api\Production\ShortageApiController;
use App\Http\Controllers\Api\Production\StockItemLedgerApiController;
use App\Http\Controllers\Api\Production\StockLedgerBrowseApiController;
use App\Http\Controllers\Api\Production\StockReportApiController;
use App\Http\Controllers\Api\Production\VehicleApiController;
use App\Http\Controllers\Api\TallyConnectorController;
use Illuminate\Support\Facades\Route;

Route::get('app-version', AppVersionController::class);

Route::post('login', [EmployeeAuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'mobile.session'])->group(function () {
    Route::post('logout', [EmployeeAuthController::class, 'logout']);
    Route::get('me', [EmployeeAuthController::class, 'me']);
    Route::post('change-password', [EmployeeAuthController::class, 'changePassword']);

    Route::post('device-tokens', [DeviceTokenController::class, 'store']);
    Route::delete('device-tokens', [DeviceTokenController::class, 'destroy']);
    Route::get('notifications', [AppNotificationController::class, 'index']);

    Route::middleware('role:employee,manager,director')->group(function () {
        Route::get('locations/maharashtra', [LocationController::class, 'maharashtra']);
        Route::get('dealers', [DealerAccountController::class, 'index']);
        Route::get('dealers/{dealer}/account-summary', [DealerAccountController::class, 'accountSummary']);
        Route::get('dealers/{dealer}/ledger', [DealerAccountController::class, 'ledger']);
        Route::get('dealers/{dealer}', [DealerAccountController::class, 'show']);
    });
    Route::post('notifications/read-all', [AppNotificationController::class, 'markAllRead']);
    Route::post('notifications/{notification}/read', [AppNotificationController::class, 'markRead']);

    Route::get(
        'dealer-applications/{dealerApplication}/documents/{dealerApplicationDocument}',
        [DealerApplicationDocumentController::class, 'show']
    );

    Route::middleware('role:employee')->group(function () {
        Route::get('employee/dashboard', EmployeeDashboardController::class);
        Route::get('employee/targets', [EmployeeDashboardController::class, 'targets']);
        Route::get('employee/dealers', EmployeeDealerController::class);
        Route::get('employee/dealer-applications', [EmployeeDealerApplicationController::class, 'index']);
        Route::post('employee/dealer-applications', [EmployeeDealerApplicationController::class, 'store']);
        Route::get('employee/dealer-applications/{dealerApplication}', [EmployeeDealerApplicationController::class, 'show']);
        Route::put('employee/dealer-applications/{dealerApplication}', [EmployeeDealerApplicationController::class, 'update']);
        Route::post('employee/dealer-applications/{dealerApplication}/submit', [EmployeeDealerApplicationController::class, 'submit']);
        Route::post('employee/dealer-applications/{dealerApplication}/documents', [EmployeeDealerApplicationController::class, 'uploadDocument']);
        Route::delete('employee/dealer-applications/{dealerApplication}/documents/{dealerApplicationDocument}', [EmployeeDealerApplicationController::class, 'deleteDocument']);
        Route::get('employee/orders', [EmployeeOrderController::class, 'index']);
        Route::post('employee/orders', [EmployeeOrderController::class, 'store']);
        Route::get('employee/orders/{order}', [EmployeeOrderController::class, 'show']);
        Route::put('employee/orders/{order}', [EmployeeOrderController::class, 'update']);
        Route::get('employee/products', EmployeeProductController::class);
        Route::get('employee/field-activity-masters/districts', [FieldActivityMasterController::class, 'districts']);
        Route::get('employee/field-activity-masters/talukas', [FieldActivityMasterController::class, 'talukas']);
        Route::get('employee/field-activity-masters/crops', [FieldActivityMasterController::class, 'crops']);
        Route::get('employee/farmers/lookup', EmployeeFarmerLookupController::class);
        Route::get('employee/collections', [EmployeeCollectionController::class, 'index']);
        Route::post('employee/collections', [EmployeeCollectionController::class, 'store']);
        Route::get('employee/collections/{collection}', [EmployeeCollectionController::class, 'show']);
        Route::get('employee/credit-notes', [EmployeeCreditNoteController::class, 'index']);
        Route::post('employee/credit-notes', [EmployeeCreditNoteController::class, 'store']);
        Route::get('employee/credit-notes/{creditNote}', [EmployeeCreditNoteController::class, 'show']);
        Route::put('employee/credit-notes/{creditNote}', [EmployeeCreditNoteController::class, 'update']);
        Route::post('employee/credit-notes/{creditNote}', [EmployeeCreditNoteController::class, 'update']);
        Route::get('employee/field-activities', [EmployeeFieldActivityController::class, 'index']);
        Route::post('employee/field-activities', [EmployeeFieldActivityController::class, 'store']);
        Route::get('employee/field-activities/{fieldActivity}', [EmployeeFieldActivityController::class, 'show']);
        Route::get('employee/tasks', [EmployeeTaskController::class, 'index']);
        Route::post('employee/tasks', [EmployeeTaskController::class, 'store']);
        Route::get('employee/tasks/{task}', [EmployeeTaskController::class, 'show']);
        Route::put('employee/tasks/{task}', [EmployeeTaskController::class, 'update']);
        Route::delete('employee/tasks/{task}', [EmployeeTaskController::class, 'destroy']);
        Route::post('employee/tasks/{task}/complete', [EmployeeTaskController::class, 'complete']);
        Route::post('employee/tasks/{task}/incomplete', [EmployeeTaskController::class, 'incomplete']);
        Route::post('employee/tasks/{task}/move-to-tomorrow', [EmployeeTaskController::class, 'moveToTomorrow']);
        Route::get('employee/dealer-visits', [EmployeeDealerVisitController::class, 'index']);
        Route::post('employee/dealer-visits', [EmployeeDealerVisitController::class, 'store']);
        Route::get('employee/dealer-visits/{dealerVisit}', [EmployeeDealerVisitController::class, 'show']);
        Route::get('employee/ta-da-rate', [EmployeeTaDaClaimController::class, 'rate']);
        Route::get('employee/ta-da-claims/travel-summary', [EmployeeTaDaClaimController::class, 'travelSummary']);
        Route::get('employee/ta-da-claims/calendar', [EmployeeTaDaClaimController::class, 'calendar']);
        Route::get('employee/ta-da-claims', [EmployeeTaDaClaimController::class, 'index']);
        Route::post('employee/ta-da-claims', [EmployeeTaDaClaimController::class, 'store']);
        Route::get('employee/ta-da-claims/{taDaClaim}', [EmployeeTaDaClaimController::class, 'show']);
        // TEST ONLY - REMOVE BEFORE PRODUCTION
        Route::post('employee/attendance/reset-today', [AttendanceController::class, 'resetToday']);
    });

    Route::middleware('role:employee,manager')->group(function () {
        Route::post('employee/route-points/batch', [EmployeeRoutePointController::class, 'storeBatch']);
    });

    Route::middleware('role:manager')->prefix('manager')->group(function () {
        Route::get('dashboard', ManagerDashboardController::class);
        Route::get('targets', [ManagerEmployeePerformanceController::class, 'targets']);
        Route::get('employees', [ManagerEmployeePerformanceController::class, 'index']);
        Route::get('employees/{employee}', [ManagerEmployeePerformanceController::class, 'show']);
        Route::get('orders', [ManagerOrderController::class, 'index']);
        Route::get('orders/{order}', [ManagerOrderController::class, 'show']);
        Route::put('orders/{order}', [ManagerOrderController::class, 'update']);
        Route::post('orders/{order}/approve', [ManagerOrderController::class, 'approve']);
        Route::post('orders/{order}/reject', [ManagerOrderController::class, 'reject']);
        Route::get('products', EmployeeProductController::class);
        Route::get('field-activity-masters/districts', [FieldActivityMasterController::class, 'districts']);
        Route::get('field-activity-masters/talukas', [FieldActivityMasterController::class, 'talukas']);
        Route::get('field-activity-masters/crops', [FieldActivityMasterController::class, 'crops']);
        Route::get('field-activities', [ManagerFieldActivityController::class, 'index']);
        Route::get('field-activities/{fieldActivity}', [ManagerFieldActivityController::class, 'show']);
        Route::get('team-attendance', [ManagerTeamAttendanceController::class, 'index']);
        Route::get('team-attendance/employees/{employee}', [ManagerTeamAttendanceController::class, 'employeeHistory']);
        Route::get('team-attendance/{attendance}', [ManagerTeamAttendanceController::class, 'show']);
        Route::get('route-tracking', [ManagerRouteTrackingController::class, 'index']);
        Route::get('route-tracking/{attendance}', [ManagerRouteTrackingController::class, 'show']);
        Route::get('team-activity', [ManagerTeamActivityController::class, 'index']);
        Route::get('team-activity/employees/{employee}', [ManagerTeamActivityController::class, 'employeeTimeline']);
        Route::get('collections', [ManagerCollectionController::class, 'index']);
        Route::get('collections/{collection}', [ManagerCollectionController::class, 'show']);
        Route::get('credit-notes', [ManagerCreditNoteController::class, 'index']);
        Route::post('credit-notes', [ManagerCreditNoteController::class, 'store']);
        Route::get('credit-notes/{creditNote}', [ManagerCreditNoteController::class, 'show']);
        Route::put('credit-notes/{creditNote}', [ManagerCreditNoteController::class, 'update']);
        Route::post('credit-notes/{creditNote}', [ManagerCreditNoteController::class, 'update']);
        Route::post('credit-notes/{creditNote}/approve', [ManagerCreditNoteController::class, 'approve']);
        Route::post('credit-notes/{creditNote}/reject', [ManagerCreditNoteController::class, 'reject']);
        Route::get('dealer-applications', [ManagerDealerApplicationController::class, 'index']);
        Route::get('dealer-applications/{dealerApplication}', [ManagerDealerApplicationController::class, 'show']);
        Route::post('dealer-applications/{dealerApplication}/approve', [ManagerDealerApplicationController::class, 'approve']);
        Route::post('dealer-applications/{dealerApplication}/reject', [ManagerDealerApplicationController::class, 'reject']);
        Route::post('dealer-applications/{dealerApplication}/send-back', [ManagerDealerApplicationController::class, 'sendBack']);
        Route::get('ta-da-claims', [ManagerTaDaClaimController::class, 'index']);
        Route::get('ta-da-claims/{taDaClaim}', [ManagerTaDaClaimController::class, 'show']);
        Route::post('ta-da-claims/{taDaClaim}/approve', [ManagerTaDaClaimController::class, 'approve']);
        Route::post('ta-da-claims/{taDaClaim}/reject', [ManagerTaDaClaimController::class, 'reject']);
    });

    Route::middleware('role:production_supervisor')->prefix('production')->group(function () {
        Route::get('dashboard', ProductionDashboardController::class);
        Route::get('orders', [ProductionOrderController::class, 'index']);
        Route::get('orders/{order}', [ProductionOrderController::class, 'show']);
        Route::post('orders/{order}/send-for-bill', [ProductionOrderController::class, 'sendForBill']);
        Route::post('orders/{order}/hold', [ProductionOrderController::class, 'hold']);
        Route::post('orders/{order}/release-hold', [ProductionOrderController::class, 'releaseHold']);
        Route::post('orders/{order}/revert-to-manager', [ProductionOrderController::class, 'revertToManager']);
        Route::post('orders/{order}/dispatch-calculation', [ProductionOrderController::class, 'calculateDispatch']);
        Route::post('orders/{order}/dispatch', [ProductionOrderController::class, 'dispatch']);
        Route::post('orders/{order}/received-copy', [ProductionOrderController::class, 'uploadReceivedCopy']);

        Route::get('vehicles', [VehicleApiController::class, 'index']);
        Route::post('vehicles', [VehicleApiController::class, 'store']);

        Route::get('inventory/dashboard', InventoryDashboardApiController::class);
        Route::get('inventory/raw-materials', [RawMaterialApiController::class, 'index']);
        Route::post('inventory/raw-materials', [RawMaterialApiController::class, 'store']);
        Route::get('inventory/raw-materials/{rawMaterial}', [RawMaterialApiController::class, 'show']);
        Route::put('inventory/raw-materials/{rawMaterial}', [RawMaterialApiController::class, 'update']);
        Route::get('inventory/packaging-materials', [PackagingMaterialApiController::class, 'index']);
        Route::get('inventory/semi-finished', [SemiFinishedMaterialApiController::class, 'index']);
        Route::get('inventory/semi-finished/{semiFinishedMaterial}', [SemiFinishedMaterialApiController::class, 'show']);
        Route::get('inventory/semi-finished/{semiFinishedMaterial}/ledger', [SemiFinishedMaterialApiController::class, 'ledger']);
        Route::get('inventory/finished-goods', [FinishedGoodsApiController::class, 'index']);
        Route::get('inventory/shortages', [ShortageApiController::class, 'index']);
        Route::get('inventory/stock-report', StockReportApiController::class);
        Route::get('inventory/stock-report/pdf', [StockReportApiController::class, 'pdf']);
        Route::get('inventory/ledger', [StockItemLedgerApiController::class, 'show']);
        Route::get('inventory/ledger/export', [StockItemLedgerApiController::class, 'export']);
        Route::get('inventory/ledger/print', [StockItemLedgerApiController::class, 'print']);
        Route::get('inventory/ledger/pdf', [StockItemLedgerApiController::class, 'pdf']);
        Route::get('inventory/stock-ledger', StockLedgerBrowseApiController::class);
        Route::get('products/manufacturable', [BomApiController::class, 'manufacturableProducts']);
        Route::get('semi-finished/manufacturable', [BomApiController::class, 'manufacturableSemiFinished']);
        Route::get('boms', [BomApiController::class, 'index']);
        Route::get('boms/active', [BomApiController::class, 'activeBom']);
        Route::get('boms/items/{bomItem}/alternates', [BomApiController::class, 'alternates']);
        Route::get('boms/{bom}', [BomApiController::class, 'show']);

        Route::post('batches/preview', [ProductionBatchApiController::class, 'preview']);
        Route::post('batches/confirm', [ProductionBatchApiController::class, 'confirm']);
        Route::get('batches', [ProductionBatchApiController::class, 'index']);
        Route::post('batches', [ProductionBatchApiController::class, 'store']);
        Route::get('batches/{batch}', [ProductionBatchApiController::class, 'show']);
        Route::put('batches/{batch}', [ProductionBatchApiController::class, 'update']);
        Route::post('batches/{batch}/submit-approval', [ProductionBatchApiController::class, 'submitApproval']);
        Route::post('batches/{batch}/start', [ProductionBatchApiController::class, 'start']);
        Route::post('batches/{batch}/complete', [ProductionBatchApiController::class, 'complete']);
        Route::post('batches/{batch}/cancel', [ProductionBatchApiController::class, 'cancel']);
        Route::get('history', [ProductionBatchApiController::class, 'history']);

        Route::get('inwards', [RawMaterialInwardApiController::class, 'index']);
        Route::post('inwards', [RawMaterialInwardApiController::class, 'store']);
        Route::post('inwards/attachment', [RawMaterialInwardAttachmentController::class, 'store']);
        Route::get('inwards/search/raw-materials', [RawMaterialInwardApiController::class, 'searchRawMaterials']);
        Route::get('inwards/search/suppliers', [RawMaterialInwardApiController::class, 'searchSuppliers']);
        Route::get('inwards/batches/{batch}', [RawMaterialInwardApiController::class, 'batchDetails']);
        Route::get('inwards/{inward}', [RawMaterialInwardApiController::class, 'show']);
        Route::put('inwards/{inward}', [RawMaterialInwardApiController::class, 'update']);
        Route::post('inwards/{inward}/post', [RawMaterialInwardApiController::class, 'post']);
        Route::post('inwards/{inward}/cancel', [RawMaterialInwardApiController::class, 'cancel']);

        Route::get('packaging-inwards', [PackagingMaterialInwardApiController::class, 'index']);
        Route::post('packaging-inwards', [PackagingMaterialInwardApiController::class, 'store']);
        Route::get('packaging-inwards/search/packaging-materials', [PackagingMaterialInwardApiController::class, 'searchPackagingMaterials']);
        Route::get('packaging-inwards/search/suppliers', [PackagingMaterialInwardApiController::class, 'searchSuppliers']);
        Route::get('packaging-inwards/{inward}', [PackagingMaterialInwardApiController::class, 'show']);
        Route::put('packaging-inwards/{inward}', [PackagingMaterialInwardApiController::class, 'update']);
        Route::post('packaging-inwards/{inward}/post', [PackagingMaterialInwardApiController::class, 'post']);
        Route::post('packaging-inwards/{inward}/cancel', [PackagingMaterialInwardApiController::class, 'cancel']);
    });

    Route::middleware('role:director')->prefix('director')->group(function () {
        Route::get('dashboard', DirectorDashboardController::class);
        Route::get('orders', [DirectorOrderController::class, 'index']);
        Route::get('orders/{order}', [DirectorOrderController::class, 'show']);
        Route::get('collections/today/dealers', [DirectorCollectionController::class, 'todayDealers']);
        Route::get('collections', [DirectorCollectionController::class, 'index']);
        Route::get('collections/{collection}', [DirectorCollectionController::class, 'show']);
        Route::get('ta-da-claims', [DirectorTaDaClaimController::class, 'index']);
        Route::get('ta-da-claims/{taDaClaim}', [DirectorTaDaClaimController::class, 'show']);
        Route::get('route-tracking', [DirectorRouteTrackingController::class, 'index']);
        Route::get('route-tracking/{attendance}', [DirectorRouteTrackingController::class, 'show']);
        Route::get('dealer-visits', [DirectorDealerVisitController::class, 'index']);
        Route::get('dealer-visits/{dealerVisit}', [DirectorDealerVisitController::class, 'show']);
        Route::get('field-visits/today', [DirectorFieldVisitController::class, 'today']);
        Route::get('field-visits/{fieldActivity}', [DirectorFieldVisitController::class, 'show']);
        Route::get('outstanding-dealers', [DirectorOutstandingDealerController::class, 'index']);
        Route::get('production-batches/pending-approvals', [DirectorProductionBatchController::class, 'pendingApprovals']);
        Route::post('production-batches/{batch}/approve-deviation', [DirectorProductionBatchController::class, 'approveDeviation']);
        Route::post('production-batches/{batch}/reject-deviation', [DirectorProductionBatchController::class, 'rejectDeviation']);
    });

    // Payment request approvals: Directors and configured approvers (policy-enforced).
    Route::prefix('director')->group(function () {
        Route::get('payment-requests/pending-count', [DirectorPaymentRequestController::class, 'pendingCount']);
        Route::get('payment-requests', [DirectorPaymentRequestController::class, 'index']);
        Route::post('payment-requests/approve-bulk', [DirectorPaymentRequestController::class, 'approveBulk']);
        Route::get('payment-requests/{paymentRequest}', [DirectorPaymentRequestController::class, 'show']);
        Route::post('payment-requests/{paymentRequest}/approve', [DirectorPaymentRequestController::class, 'approve']);
        Route::post('payment-requests/{paymentRequest}/reject', [DirectorPaymentRequestController::class, 'reject']);
        Route::get(
            'payment-requests/{paymentRequest}/supporting-documents/{supportingDocument}',
            [PaymentRequestSupportingDocumentController::class, 'show']
        );
    });
});

Route::middleware(['auth:sanctum', 'role:employee,manager'])->prefix('attendance')->group(function () {
    Route::post('punch-in', [AttendanceController::class, 'punchIn']);
    Route::post('punch-out', [AttendanceController::class, 'punchOut']);
    Route::get('today', [AttendanceController::class, 'today']);
    Route::get('history', [AttendanceController::class, 'history']);
    Route::get('monthly-summary', [AttendanceController::class, 'monthlySummary']);
});

Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get('employee-routes/{attendance}', [AdminEmployeeRouteController::class, 'show']);
});

Route::middleware(['auth:sanctum', 'tally.connector', 'throttle:60,1'])
    ->prefix('tally-connector')
    ->group(function (): void {
        Route::get('pending', [TallyConnectorController::class, 'pending']);
        Route::post('vouchers/{tallyOutboundVoucher}/claim', [TallyConnectorController::class, 'claim']);
        Route::post('vouchers/{tallyOutboundVoucher}/synced', [TallyConnectorController::class, 'synced']);
        Route::post('vouchers/{tallyOutboundVoucher}/failed', [TallyConnectorController::class, 'failed']);
    });
