<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCurrentTeam;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReminderSetting extends Model
{
    use HasFactory;
    use BelongsToCurrentTeam;

    #[\Override]
    protected $fillable = [
        'days_before_reminder',
        'reminder_frequency_days',
        'max_reminders',
        'is_active',
    ];

    #[\Override]
    protected $casts = [
        'is_active' => 'boolean',
    ];
}
