<?php

namespace App\Exceptions;

use App\Services\Dealers\DealerCreditAssessment;
use RuntimeException;

final class CreditLimitExceededException extends RuntimeException
{
    public function __construct(public readonly DealerCreditAssessment $assessment)
    {
        parent::__construct('Credit Limit Exceeded');
    }
}
