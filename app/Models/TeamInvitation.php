<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCurrentTeam;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Jetstream\TeamInvitation as JetstreamTeamInvitation;

class TeamInvitation extends JetstreamTeamInvitation
{
    use BelongsToCurrentTeam;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    #[\Override]
    protected $fillable = [
        'email',
    ];

    /**
     * Get the team that the invitation belongs to.
     */
    #[\Override]
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
