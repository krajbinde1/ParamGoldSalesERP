<?php

namespace App\Actions\FieldActivities;

use App\Models\Crop;
use App\Models\Employee;
use App\Models\Farmer;
use App\Models\FieldActivity;
use App\Models\MaharashtraDistrict;
use App\Models\MaharashtraTaluka;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordFieldActivity
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{product_id: int, dosage?: ?string, remark?: ?string}>  $recommendations
     */
    public function execute(
        Employee $employee,
        array $data,
        array $recommendations,
        UploadedFile $photo,
    ): FieldActivity {
        $district = MaharashtraDistrict::query()
            ->whereKey($data['district_id'])
            ->where('status', true)
            ->first();
        $taluka = MaharashtraTaluka::query()
            ->whereKey($data['taluka_id'])
            ->where('district_id', $data['district_id'])
            ->where('status', true)
            ->first();
        $crop = Crop::query()->whereKey($data['crop_id'])->where('status', true)->first();

        if ($district === null || $taluka === null || $crop === null) {
            throw ValidationException::withMessages(array_filter([
                'district_id' => $district === null ? 'Select a valid Maharashtra district.' : null,
                'taluka_id' => $taluka === null ? 'Select a taluka that belongs to the selected district.' : null,
                'crop_id' => $crop === null ? 'Select a valid crop.' : null,
            ]));
        }

        $productIds = collect($recommendations)->pluck('product_id')->map(fn ($id): int => (int) $id)->all();
        $validProductCount = Product::query()->whereIn('id', $productIds)->where('status', true)->count();
        if ($validProductCount !== count(array_unique($productIds))) {
            throw ValidationException::withMessages([
                'recommendations' => 'Each recommendation must use an active product from the product master.',
            ]);
        }

        return DB::transaction(function () use ($employee, $data, $recommendations, $photo, $district, $taluka, $crop): FieldActivity {
            $now = FieldActivity::businessNow();
            $farmer = $this->upsertFarmer($employee, $data, $district, $taluka, $now->toDateString());

            $photoPath = str_replace('\\', '/', $photo->store('field-activities', 'public'));

            $activity = FieldActivity::query()->create([
                'employee_id' => $employee->id,
                'farmer_id' => $farmer->id,
                'farmer_name' => $farmer->name,
                'farmer_mobile' => $farmer->mobile,
                'district_id' => $district->id,
                'district' => $district->name,
                'taluka_id' => $taluka->id,
                'taluka' => $taluka->name,
                'village' => $farmer->village,
                'crop_id' => $crop->id,
                'activity_type' => $data['activity_type'] ?? 'farmer_visit',
                'remark' => filled($data['remark'] ?? null) ? trim((string) $data['remark']) : null,
                'activity_date' => $now->toDateString(),
                'activity_time' => $now->format('H:i:s'),
                'photo_path' => $photoPath,
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'status' => FieldActivity::STATUS_COMPLETED,
            ]);

            foreach (array_values($recommendations) as $index => $row) {
                $activity->recommendations()->create([
                    'crop_id' => $crop->id,
                    'product_id' => (int) $row['product_id'],
                    'dosage' => filled($row['dosage'] ?? null) ? trim((string) $row['dosage']) : null,
                    'remark' => filled($row['remark'] ?? null) ? trim((string) $row['remark']) : null,
                    'sort_order' => $index + 1,
                ]);
            }

            return $activity->load([
                'employee:id,full_name',
                'farmer.district',
                'farmer.taluka',
                'crop',
                'recommendations.product',
                'recommendations.crop',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertFarmer(
        Employee $employee,
        array $data,
        MaharashtraDistrict $district,
        MaharashtraTaluka $taluka,
        string $activityDate,
    ): Farmer {
        $mobile = preg_replace('/\D+/', '', (string) $data['farmer_mobile']) ?? '';
        $name = trim((string) $data['farmer_name']);
        $village = trim((string) $data['village']);

        $farmer = Farmer::query()->where('mobile', $mobile)->lockForUpdate()->first();

        if ($farmer === null) {
            return Farmer::query()->create([
                'name' => $name,
                'mobile' => $mobile,
                'district_id' => $district->id,
                'taluka_id' => $taluka->id,
                'village' => $village,
                'created_by_employee_id' => $employee->id,
                'first_contact_date' => $activityDate,
                'last_activity_date' => $activityDate,
                'status' => true,
            ]);
        }

        $farmer->fill([
            'name' => $name,
            'district_id' => $district->id,
            'taluka_id' => $taluka->id,
            'village' => $village,
            'last_activity_date' => $activityDate,
        ]);
        if ($farmer->first_contact_date === null) {
            $farmer->first_contact_date = $activityDate;
        }
        $farmer->save();

        return $farmer;
    }
}
