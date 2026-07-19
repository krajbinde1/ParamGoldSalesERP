<?php

use App\Http\Controllers\Api\AdminEmployeeRouteController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\Director\DirectorDashboardController;
use App\Http\Controllers\Api\Director\DirectorOrderController;
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
use App\Http\Controllers\Api\Production\ProductionDashboardController;
use App\Http\Controllers\Api\Production\ProductionOrderController;
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
    });

    Route::middleware('role:director')->prefix('director')->group(function () {
        Route::get('dashboard', DirectorDashboardController::class);
        Route::get('orders', [DirectorOrderController::class, 'index']);
        Route::get('orders/{order}', [DirectorOrderController::class, 'show']);
        Route::get('ta-da-claims', [DirectorTaDaClaimController::class, 'index']);
        Route::get('ta-da-claims/{taDaClaim}', [DirectorTaDaClaimController::class, 'show']);
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
