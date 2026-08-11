<?php

namespace App\Services\SafeDelete;

use Illuminate\Validation\ValidationException;

final class SafeDeleteBlockedException extends ValidationException
{
    public static function fromAssessment(SafeDeleteAssessment $assessment): self
    {
        $exception = static::withMessages([
            'delete' => $assessment->shortMessage(),
        ]);

        $exception->assessment = $assessment;
        $exception->errorBag = [
            'delete' => [$assessment->message()],
        ];

        return $exception;
    }

    public ?SafeDeleteAssessment $assessment = null;
}
