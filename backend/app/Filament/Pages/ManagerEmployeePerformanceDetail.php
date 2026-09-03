<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Employee;
use App\Models\Order;
use App\Services\Dashboard\DashboardMetricsService;
use Filament\Actions\ViewAction;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;

class ManagerEmployeePerformanceDetail extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'manager-employee-performance/{employee}';

    protected static ?string $title = 'Employee Performance';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.manager-employee-performance-detail';

    public Employee $employee;

    /**
     * @var array<string, mixed>
     */
    public array $performance = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->usesManagerDashboard()
            || auth()->user()?->usesAdminDirectorDashboard()
            ?? false;
    }

    public function mount(Employee $employee): void
    {
        $this->ensureEmployeeRoleTarget($employee);

        $this->employee = $employee->loadMissing([
            'user:id,employee_id,role',
            'reportingManager:id,full_name',
        ]);

        $this->loadPerformance();
    }

    public function getHeading(): string|Htmlable
    {
        return 'Employee Performance';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return new HtmlString(e($this->employee->full_name));
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [
            Dashboard::getUrl() => 'Dashboard',
            url()->current() => 'Employee Performance',
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Employee Orders')
            ->query(fn (): Builder => $this->getTableQuery())
            ->columns([
                TextColumn::make('order_no')
                    ->label('Order Number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('order_date')
                    ->label('Order Date')
                    ->date()
                    ->sortable(),
                TextColumn::make('dealer.firm_name')
                    ->label('Dealer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Order::statusLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => Order::statusColor($state))
                    ->sortable(),
                TextColumn::make('grand_total')
                    ->label('Grand Total')
                    ->money('INR')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending_approval' => 'Pending Approval',
                        'approved' => 'Approved',
                        'dispatched' => 'Dispatched',
                        'rejected' => 'Rejected',
                    ])
                    ->placeholder('All'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record])),
            ])
            ->defaultSort('order_date', 'desc');
    }

    protected function getTableQuery(): Builder
    {
        return Order::query()
            ->with(['dealer:id,firm_name'])
            ->where('sales_employee_id', $this->employee->id);
    }

    protected function loadPerformance(): void
    {
        $metrics = app(DashboardMetricsService::class);
        $start = Carbon::now('Asia/Kolkata')->startOfMonth();
        $end = Carbon::now('Asia/Kolkata')->endOfMonth();

        $this->performance = $metrics->employeePerformanceRow($this->employee, $start, $end, 'month');
    }

    public function formatCurrency(float $amount): string
    {
        return Number::currency($amount, 'INR', 'en_IN');
    }

    public function formatPercentage(float $percentage): string
    {
        return number_format($percentage, 2).'%';
    }

    private function ensureEmployeeRoleTarget(Employee $employee): void
    {
        if (! $employee->status || $employee->trashed()) {
            abort(404, 'Employee not found.');
        }

        $user = $employee->user;

        if ($user === null || ! $user->hasRole(UserRole::Employee)) {
            abort(403, 'You can only view employee-role performance.');
        }
    }
}
