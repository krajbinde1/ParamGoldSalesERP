<?php

namespace App\Providers;

use App\Models\Dealer;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Product;
use App\Models\TaDaClaim;
use App\Policies\DealerPolicy;
use App\Policies\EmployeeLoginAccessPolicy;
use App\Policies\OrderPolicy;
use App\Policies\ProductPolicy;
use App\Policies\TaDaClaimPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Employee::class, EmployeeLoginAccessPolicy::class);
        Gate::policy(Dealer::class, DealerPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(TaDaClaim::class, TaDaClaimPolicy::class);
    }
}
