<?php

namespace App\Rules;

use App\Support\MaharashtraGeography;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class MaharashtraDistrictRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (MaharashtraGeography::canonicalDistrictName(is_string($value) ? $value : null) === null) {
            $fail('Select a valid Maharashtra district.');
        }
    }
}
