<?php

use App\Http\Controllers\Api\AdminEmployeeRouteController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\Director\DirectorDashboardController;
use App\Http\Controllers\Api\Director\DirectorOrderController;
use App\Http\Controllers\Api\Director\DirectorProductionBatchController;
use App\Http\Controllers\Api\Director\DirectorTaDaClaimController;
use App\Http\Controllers\Api\EmployeeRoutePointController;
use App\Http\Controllers\Api\EmployeeAuthController;
use App\Http\Controllers\Api\EmployeeCollectionController;
use App\Http\Controllers\Api\EmployeeDashboardController;
use App\Http\Controllers\Api\EmployeeTaDaClaimController;
use App\Http\Controllers\Api\EmployeeDealerVisitController;
use App\Http\Controllers\Api\EmployeeFieldActivityController;
use App\Http\Controllers\Api\EmployeeDealerController;
use App\Http\Controllers\Api\EmployeeOrderController;
use App\Http\Controllers\Api\EmployeeProductController;
use App\Http\Controllers\Api\Manager\ManagerDashboardController;
use App\Http\Controllers\Api\Manager\ManagerEmployeePerformanceController;
use App\Http\Controllers\Api\Manager\ManagerOrderController;
use App\Http\Controllers\Api\Manager\ManagerTaDaClaimController;
        use App\Http\Controllers\Api\Production\BomApiController;
use App\Http\Controllers\Api\Production\FinishedGoodsApiController;
use App\Http\Controllers\Api\Production\InventoryDashboardApiController;
use App\Http\Controllers\Api\Production\PackagingMaterialApiController;
use App\Http\Controllers\Api\Production\ProductionBatchApiController;
use App\Http\Controllers\Api\Production\ProductionDashboardController;
use App\Http\Controllers\Api\Production\ProductionOrderController;
use App\Http\Controllers\Api\Production\PackagingMaterialInwardApiController;
use App\Http\Controllers\Api\Production\RawMaterialApiController;
use App\Http\Controllers\Api\Production\RawMaterialInwardApiController;
use App\Http\Controllers\Api\Production\SemiFinishedMaterialApiController;
use App\Http\Controllers\Api\Production\ShortageApiController;
use App\Http\Controllers\Api\Production\StockItemLedgerApiController;
use App\Http\Controllers\Api\Production\StockReportApiController;
use Illuminate\Support\Facades\Route;

Route::post('login', [EmployeeAuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [EmployeeAuthController::class, 'logout']);
    Route::get('me', [EmployeeAuthController::class, 'me']);
    Route::post('change-password', [EmployeeAuthController::class, 'changePassword']);

    Route::middleware('role:employee')->group(function () {
        Route::get('employee/dashboard', EmployeeDashboardController::class);
        Route::get('employee/dealers', EmployeeDealerController::class);
        Route::get('employee/orders', [EmployeeOrderController::class, 'index']);
        Route::post('employee/orders', [EmployeeOrderController::class, 'store']);
        Route::get('employee/orders/{order}', [EmployeeOrderController::class, 'show']);
        Route::put('employee/orders/{order}', [EmployeeOrderController::class, 'update']);
        Route::get('employee/products', EmployeeProductController::class);
        Route::get('employee/collections', [EmployeeCollectionController::class, 'index']);
        Route::post('employee/collections', [EmployeeCollectionController::class, 'store']);
        Route::get('employee/collections/{collection}', [EmployeeCollectionController::class, 'show']);
        Route::get('employee/field-activities', [EmployeeFieldActivityController::class, 'index']);
        Route::post('employee/field-activities', [EmployeeFieldActivityController::class, 'store']);
        Route::get('employee/field-activities/{fieldActivity}', [EmployeeFieldActivityController::class, 'show']);
        Route::get('employee/dealer-visits', [EmployeeDealerVisitController::class, 'index']);
        Route::post('employee/dealer-visits', [EmployeeDealerVisitController::class, 'store']);
        Route::get('employee/dealer-visits/{dealerVisit}', [EmployeeDealerVisitController::class, 'show']);
        Route::get('employee/ta-da-rate', [EmployeeTaDaClaimController::class, 'rate']);
        Route::get('employee/ta-da-claims/travel-summary', [EmployeeTaDaClaimController::class, 'travelSummary']);
        Route::get('employee/ta-da-claims/calendar', [EmployeeTaDaClaimController::class, 'calendar']);
        Route::get('employee/ta-da-claims', [EmployeeTaDaClaimController::class, 'index']);
        Route::post('employee/ta-da-claims', [EmployeeTaDaClaimController::class, 'store']);
        Route::get('employee/ta-da-claims/{taDaClaim}', [EmployeeTaDaClaimController::class, 'show']);
        Route::post('employee/route-points/batch', [EmployeeRoutePointController::class, 'storeBatch']);
        // TEST ONLY - REMOVE BEFORE PRODUCTION
        Route::post('employee/attendance/reset-today', [AttendanceController::class, 'resetToday']);
    });

    Route::middleware('role:manager')->prefix('manager')->group(function () {
        Route::get('dashboard', ManagerDashboardController::class);
        Route::get('employees', [ManagerEmployeePerformanceController::class, 'index']);
        Route::get('employees/{employee}', [ManagerEmployeePerformanceController::class, 'show']);
        Route::get('orders', [ManagerOrderController::class, 'index']);
        Route::get('orders/{order}', [ManagerOrderController::class, 'show']);
        Route::post('orders/{order}/approve', [ManagerOrderController::class, 'approve']);
        Route::post('orders/{order}/reject', [ManagerOrderController::class, 'reject']);
        Route::get('ta-da-claims', [ManagerTaDaClaimController::class, 'index']);
        Route::get('ta-da-claims/{taDaClaim}', [ManagerTaDaClaimController::class, 'show']);
        Route::post('ta-da-claims/{taDaClaim}/approve', [ManagerTaDaClaimController::class, 'approve']);
        Route::post('ta-da-claims/{taDaClaim}/reject', [ManagerTaDaClaimController::class, 'reject']);
    });

    Route::middleware('role:production_supervisor')->prefix('production')->group(function () {
        Route::get('dashboard', ProductionDashboardController::class);
        Route::get('orders', [ProductionOrderController::class, 'index']);
        Route::get('orders/{order}', [ProductionOrderController::class, 'show']);
        Route::post('orders/{order}/dispatch-calculation', [ProductionOrderController::class, 'calculateDispatch']);
        Route::post('orders/{order}/dispatch', [ProductionOrderController::class, 'dispatch']);

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
        Route::get('inventory/stock-ledger', \App\Http\Controllers\Api\Production\StockLedgerBrowseApiController::class);
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
        Route::post('inwards/attachment', [\App\Http\Controllers\Api\Production\RawMaterialInwardAttachmentController::class, 'store']);
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
        Route::get('ta-da-claims', [DirectorTaDaClaimController::class, 'index']);
        Route::get('ta-da-claims/{taDaClaim}', [DirectorTaDaClaimController::class, 'show']);
        Route::get('production-batches/pending-approvals', [DirectorProductionBatchController::class, 'pendingApprovals']);
        Route::post('production-batches/{batch}/approve-deviation', [DirectorProductionBatchController::class, 'approveDeviation']);
        Route::post('production-batches/{batch}/reject-deviation', [DirectorProductionBatchController::class, 'rejectDeviation']);
    });
});

Route::middleware(['auth:sanctum', 'role:employee'])->prefix('attendance')->group(function () {
    Route::post('punch-in', [AttendanceController::class, 'punchIn']);
    Route::post('punch-out', [AttendanceController::class, 'punchOut']);
    Route::get('today', [AttendanceController::class, 'today']);
    Route::get('history', [AttendanceController::class, 'history']);
    Route::get('monthly-summary', [AttendanceController::class, 'monthlySummary']);
});

Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get('employee-routes/{attendance}', [AdminEmployeeRouteController::class, 'show']);
});
