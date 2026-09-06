<?php

namespace App\Enums;

enum CompanyTransportLedgerSource: string
{
    case OrderDispatch = 'order_dispatch';
    case OrderCorrection = 'order_correction';
    case Expense = 'expense';
}
