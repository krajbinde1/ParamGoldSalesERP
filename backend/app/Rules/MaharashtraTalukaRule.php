<?php

namespace App\Rules;

use App\Support\MaharashtraGeography;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class MaharashtraTalukaRule implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly ?string $district = null) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $district = $this->district ?? $this->districtValue();
        if (MaharashtraGeography::canonicalTalukaName($district, is_string($value) ? $value : null) === null) {
            $fail('Selected taluka does not belong to the selected district.');
        }
    }

    private function districtValue(): ?string
    {
        foreach ([
            $this->data['district'] ?? null,
            data_get($this->data, 'data.district'),
        ] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
