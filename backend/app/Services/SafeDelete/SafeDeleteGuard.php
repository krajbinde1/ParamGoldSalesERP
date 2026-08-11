<?php

namespace App\Services\SafeDelete;

use App\Models\Attendance;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\BomItemAlternate;
use App\Models\Collection;
use App\Models\Dealer;
use App\Models\DealerVisit;
use App\Models\Employee;
use App\Models\EmployeeRoutePoint;
use App\Models\FieldActivity;
use App\Models\FinishedProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PackagingMaterial;
use App\Models\PackagingMaterialInward;
use App\Models\PackagingMaterialInwardItem;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchConsumption;
use App\Models\RawMaterial;
use App\Models\RawMaterialBatch;
use App\Models\RawMaterialInward;
use App\Models\RawMaterialInwardItem;
use App\Models\RawMaterialInwardReturn;
use App\Models\SemiFinishedMaterial;
use App\Models\StockAdjustment;
use App\Models\StockLedger;
use App\Models\Supplier;
use App\Models\TaDaClaim;
use App\Models\User;
use App\Models\WeeklyTarget;
use Illuminate\Database\Eloquent\Model;

/**
 * Central Safe Delete gate for ERP master records.
 *
 * Unused masters may be permanently deleted.
 * Masters with transactional/history dependencies must be deactivated instead.
 */
final class SafeDeleteGuard
{
    public function canDelete(Model $record): bool
    {
        return $this->assess($record)->allowed;
    }

    public function assertCanDelete(Model $record): void
    {
        $assessment = $this->assess($record);

        if ($assessment->blocked()) {
            throw SafeDeleteBlockedException::fromAssessment($assessment);
        }
    }

    public function assess(Model $record): SafeDeleteAssessment
    {
        return match ($record::class) {
            Dealer::class => $this->assessDealer($record),
            Employee::class => $this->assessEmployee($record),
            Product::class => $this->assessProduct($record),
            FinishedProduct::class => $this->assessFinishedProduct($record),
            RawMaterial::class => $this->assessRawMaterial($record),
            PackagingMaterial::class => $this->assessPackagingMaterial($record),
            SemiFinishedMaterial::class => $this->assessSemiFinishedMaterial($record),
            Supplier::class => $this->assessSupplier($record),
            Bom::class => $this->assessBom($record),
            User::class => $this->assessUser($record),
            default => new SafeDeleteAssessment(
                record: $record,
                entityLabel: class_basename($record),
                allowed: true,
                supportsDeactivate: false,
            ),
        };
    }

    /**
     * Soft-deactivate a master when permanent delete is blocked.
     */
    public function deactivate(Model $record): Model
    {
        if ($record instanceof Bom) {
            $record->forceFill(['status' => \App\Enums\BomStatus::Inactive])->save();

            return $record->refresh();
        }

        if ($record instanceof Employee) {
            $record->forceFill(['status' => false])->save();

            $user = $record->user;
            if ($user instanceof User) {
                $user->tokens()->delete();
            }

            return $record->refresh();
        }

        if ($record->isFillable('status') || array_key_exists('status', $record->getAttributes())) {
            $record->forceFill(['status' => false])->save();

            return $record->refresh();
        }

        throw SafeDeleteBlockedException::withMessages([
            'deactivate' => 'This record does not support Active / Inactive status.',
        ]);
    }

    private function assessDealer(Dealer $dealer): SafeDeleteAssessment
    {
        return $this->makeAssessment($dealer, 'dealer', [
            ['Orders', Order::query()->where('dealer_id', $dealer->id)->count()],
            ['Collections', Collection::query()->where('dealer_id', $dealer->id)->count()],
            ['Dealer Visits', DealerVisit::query()->where('dealer_id', $dealer->id)->count()],
        ]);
    }

    private function assessEmployee(Employee $employee): SafeDeleteAssessment
    {
        return $this->makeAssessment($employee, 'employee', [
            ['Assigned Dealers', $employee->assignedDealers()->count()],
            ['Orders', Order::query()->where('sales_employee_id', $employee->id)->count()],
            ['Collections', Collection::query()->where('sales_employee_id', $employee->id)->count()],
            ['Attendance', Attendance::query()->where('employee_id', $employee->id)->count()],
            ['Route Points', EmployeeRoutePoint::query()->where('employee_id', $employee->id)->count()],
            ['Dealer Visits', DealerVisit::query()->where('employee_id', $employee->id)->count()],
            ['Field Activities', FieldActivity::query()->where('employee_id', $employee->id)->count()],
            ['TA/DA Claims', TaDaClaim::query()->where('employee_id', $employee->id)->count()],
            ['Weekly Targets', WeeklyTarget::query()->where('employee_id', $employee->id)->count()],
            ['Direct Reports', Employee::query()->where('reporting_manager_id', $employee->id)->count()],
        ]);
    }

    private function assessProduct(Product $product): SafeDeleteAssessment
    {
        return $this->makeAssessment($product, 'product', [
            ['Orders', OrderItem::query()->where('product_id', $product->id)->count()],
            ['Bill of Materials', Bom::query()->where('product_id', $product->id)->count()],
            ['Production Batches', ProductionBatch::query()->where('product_id', $product->id)->count()],
            ['Stock Ledger', StockLedger::query()->where('product_id', $product->id)->count()],
            ['Stock Adjustments', StockAdjustment::query()->where('product_id', $product->id)->count()],
        ], supportsDeactivate: true);
    }

    private function assessFinishedProduct(FinishedProduct $finishedProduct): SafeDeleteAssessment
    {
        // Finished Product is a sidecar of Product — block only when the product has transactional usage.
        $productId = (int) $finishedProduct->product_id;

        return $this->makeAssessment($finishedProduct, 'finished goods record', [
            ['Orders', OrderItem::query()->where('product_id', $productId)->count()],
            ['Bill of Materials', Bom::query()->where('product_id', $productId)->count()],
            ['Production Batches', ProductionBatch::query()->where('product_id', $productId)->count()],
            ['Stock Ledger', StockLedger::query()->where('product_id', $productId)->count()],
            ['Stock Adjustments', StockAdjustment::query()->where('product_id', $productId)->count()],
        ], supportsDeactivate: true);
    }

    private function assessRawMaterial(RawMaterial $material): SafeDeleteAssessment
    {
        return $this->makeAssessment($material, 'raw material', [
            ['Material Inward Lines', RawMaterialInwardItem::query()->where('raw_material_id', $material->id)->count()],
            ['Material Batches', RawMaterialBatch::query()->where('raw_material_id', $material->id)->count()],
            ['Inward Returns', RawMaterialInwardReturn::query()->where('raw_material_id', $material->id)->count()],
            ['BOM Components', BomItem::query()->where('raw_material_id', $material->id)->count()],
            ['BOM Alternates', BomItemAlternate::query()->where('raw_material_id', $material->id)->count()],
            ['Production Consumptions', ProductionBatchConsumption::query()->where('raw_material_id', $material->id)->count()],
            ['Stock Ledger', StockLedger::query()->where('raw_material_id', $material->id)->count()],
            ['Stock Adjustments', StockAdjustment::query()->where('raw_material_id', $material->id)->count()],
        ]);
    }

    private function assessPackagingMaterial(PackagingMaterial $material): SafeDeleteAssessment
    {
        return $this->makeAssessment($material, 'packaging material', [
            ['Material Inward Lines', PackagingMaterialInwardItem::query()->where('packaging_material_id', $material->id)->count()],
            ['BOM Components', BomItem::query()->where('packaging_material_id', $material->id)->count()],
            ['BOM Alternates', BomItemAlternate::query()->where('packaging_material_id', $material->id)->count()],
            ['Production Consumptions', ProductionBatchConsumption::query()->where('packaging_material_id', $material->id)->count()],
            ['Stock Ledger', StockLedger::query()->where('packaging_material_id', $material->id)->count()],
            ['Stock Adjustments', StockAdjustment::query()->where('packaging_material_id', $material->id)->count()],
        ]);
    }

    private function assessSemiFinishedMaterial(SemiFinishedMaterial $material): SafeDeleteAssessment
    {
        return $this->makeAssessment($material, 'semi-finished material', [
            ['BOMs (as output)', Bom::query()->where('semi_finished_id', $material->id)->count()],
            ['BOM Components', BomItem::query()->where('semi_finished_id', $material->id)->count()],
            ['Production Batches', ProductionBatch::query()->where('semi_finished_id', $material->id)->count()],
            ['Production Consumptions', ProductionBatchConsumption::query()->where('semi_finished_id', $material->id)->count()],
            ['Stock Ledger', StockLedger::query()->where('semi_finished_id', $material->id)->count()],
            ['Stock Adjustments', StockAdjustment::query()->where('semi_finished_id', $material->id)->count()],
        ]);
    }

    private function assessSupplier(Supplier $supplier): SafeDeleteAssessment
    {
        $packagingInwards = 0;

        if (\Illuminate\Support\Facades\Schema::hasColumn('packaging_material_inwards', 'supplier_id')) {
            $packagingInwards = PackagingMaterialInward::query()->where('supplier_id', $supplier->id)->count();
        }

        return $this->makeAssessment($supplier, 'supplier', [
            ['Raw Material Inwards', RawMaterialInward::query()->where('supplier_id', $supplier->id)->count()],
            ['Packaging Material Inwards', $packagingInwards],
        ]);
    }

    private function assessBom(Bom $bom): SafeDeleteAssessment
    {
        return $this->makeAssessment($bom, 'BOM', [
            ['Production Batches', $bom->productionBatches()->count()],
        ], supportsDeactivate: true);
    }

    private function assessUser(User $user): SafeDeleteAssessment
    {
        // Users are managed via Employee lifecycle — block direct delete when linked to an employee with history.
        if ($user->employee_id) {
            $employee = Employee::query()->find($user->employee_id);

            if ($employee instanceof Employee) {
                $assessment = $this->assessEmployee($employee);

                return new SafeDeleteAssessment(
                    record: $user,
                    entityLabel: 'user account',
                    allowed: $assessment->allowed,
                    dependencies: $assessment->dependencies,
                    supportsDeactivate: true,
                );
            }
        }

        return new SafeDeleteAssessment(
            record: $user,
            entityLabel: 'user account',
            allowed: true,
            supportsDeactivate: false,
        );
    }

    /**
     * @param  list<array{0: string, 1: int}>  $counts
     */
    private function makeAssessment(
        Model $record,
        string $entityLabel,
        array $counts,
        bool $supportsDeactivate = true,
    ): SafeDeleteAssessment {
        $dependencies = [];

        foreach ($counts as [$label, $count]) {
            if ($count > 0) {
                $dependencies[] = new SafeDeleteDependency($label, $count);
            }
        }

        return new SafeDeleteAssessment(
            record: $record,
            entityLabel: $entityLabel,
            allowed: $dependencies === [],
            dependencies: $dependencies,
            supportsDeactivate: $supportsDeactivate,
        );
    }
}
