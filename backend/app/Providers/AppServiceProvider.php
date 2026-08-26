<?php

namespace App\Providers;

use App\Models\Bom;
use App\Models\Collection;
use App\Models\CreditNote;
use App\Models\Crop;
use App\Models\Dealer;
use App\Models\DealerApplication;
use App\Models\Employee;
use App\Models\Farmer;
use App\Models\Order;
use App\Models\OrderEditPermissionRequest;
use App\Models\PackagingMaterial;
use App\Models\PackagingMaterialInward;
use App\Models\PaymentRequest;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\RawMaterial;
use App\Models\RawMaterialInward;
use App\Models\SemiFinishedMaterial;
use App\Models\StockAdjustment;
use App\Models\StockLedger;
use App\Models\TaDaClaim;
use App\Observers\CollectionObserver;
use App\Observers\CreditNoteObserver;
use App\Observers\OrderObserver;
use App\Observers\PaymentRequestObserver;
use App\Policies\BomPolicy;
use App\Policies\CreditNotePolicy;
use App\Policies\CropPolicy;
use App\Policies\DealerApplicationPolicy;
use App\Policies\DealerPolicy;
use App\Policies\EmployeeLoginAccessPolicy;
use App\Policies\FarmerPolicy;
use App\Policies\OrderEditPermissionRequestPolicy;
use App\Policies\OrderPolicy;
use App\Policies\PackagingMaterialInwardPolicy;
use App\Policies\PackagingMaterialPolicy;
use App\Policies\PaymentRequestPolicy;
use App\Policies\ProductionBatchPolicy;
use App\Policies\ProductPolicy;
use App\Policies\RawMaterialInwardPolicy;
use App\Policies\RawMaterialPolicy;
use App\Policies\SemiFinishedMaterialPolicy;
use App\Policies\StockAdjustmentPolicy;
use App\Policies\StockLedgerPolicy;
use App\Policies\TaDaClaimPolicy;
use App\Services\Dashboard\DirectorDashboardDataService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(DirectorDashboardDataService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Employee::class, EmployeeLoginAccessPolicy::class);
        Gate::policy(Dealer::class, DealerPolicy::class);
        Gate::policy(DealerApplication::class, DealerApplicationPolicy::class);
        Gate::policy(Farmer::class, FarmerPolicy::class);
        Gate::policy(Crop::class, CropPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(CreditNote::class, CreditNotePolicy::class);
        Gate::policy(OrderEditPermissionRequest::class, OrderEditPermissionRequestPolicy::class);
        Gate::policy(TaDaClaim::class, TaDaClaimPolicy::class);
        Gate::policy(PaymentRequest::class, PaymentRequestPolicy::class);
        Gate::policy(RawMaterial::class, RawMaterialPolicy::class);
        Gate::policy(RawMaterialInward::class, RawMaterialInwardPolicy::class);
        Gate::policy(PackagingMaterialInward::class, PackagingMaterialInwardPolicy::class);
        Gate::policy(PackagingMaterial::class, PackagingMaterialPolicy::class);
        Gate::policy(SemiFinishedMaterial::class, SemiFinishedMaterialPolicy::class);
        Gate::policy(Bom::class, BomPolicy::class);
        Gate::policy(ProductionBatch::class, ProductionBatchPolicy::class);
        Gate::policy(StockLedger::class, StockLedgerPolicy::class);
        Gate::policy(StockAdjustment::class, StockAdjustmentPolicy::class);

        Order::observe(OrderObserver::class);
        Collection::observe(CollectionObserver::class);
        CreditNote::observe(CreditNoteObserver::class);
        PaymentRequest::observe(PaymentRequestObserver::class);
    }
}
