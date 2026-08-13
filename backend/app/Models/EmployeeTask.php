<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeTask extends Model
{
    protected $fillable = [
        'employee_id',
        'title',
        'note',
        'due_date',
        'due_time',
        'is_important',
        'is_completed',
        'completed_at',
        'reminder_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'due_time' => 'datetime:H:i',
            'reminder_at' => 'datetime',
            'completed_at' => 'datetime',
            'is_important' => 'boolean',
            'is_completed' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
