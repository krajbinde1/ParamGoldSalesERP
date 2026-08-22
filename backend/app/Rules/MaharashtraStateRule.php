<?php

namespace App\Rules;

use App\Support\MaharashtraGeography;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class MaharashtraStateRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! MaharashtraGeography::isValidState(is_string($value) ? $value : null)) {
            $fail('State must be Maharashtra.');
        }
    }
}
