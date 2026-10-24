<?php

namespace App\Models;

use Database\Factories\QuickBooksConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $connected_by
 * @property string $realm_id
 * @property string $company_name
 * @property string $slug
 * @property string $access_token
 * @property string $refresh_token
 * @property Carbon $access_token_expires_at
 * @property Carbon $refresh_token_expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User|null $connector
 */
#[Fillable([
    'team_id',
    'connected_by',
    'realm_id',
    'company_name',
    'slug',
    'access_token',
    'refresh_token',
    'access_token_expires_at',
    'refresh_token_expires_at',
])]
#[Hidden(['access_token', 'refresh_token'])]
class QuickBooksConnection extends Model
{
    /** @use HasFactory<QuickBooksConnectionFactory> */
    use HasFactory;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (QuickBooksConnection $connection) {
            if (empty($connection->slug)) {
                $connection->slug = static::generateUniqueSlug($connection->company_name);
            }
        });
    }

    /**
     * Generate a slug for the company name that is not used by another connection.
     */
    public static function generateUniqueSlug(string $companyName): string
    {
        $base = Str::slug($companyName) ?: 'company';
        $slug = $base;
        $suffix = 1;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * Get the team that owns the connection.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user who authorized the connection.
     *
     * @return BelongsTo<User, $this>
     */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    /**
     * Determine if the access token has expired or is about to expire.
     */
    public function accessTokenIsExpiring(): bool
    {
        return $this->access_token_expires_at->subMinutes(2)->isPast();
    }

    /**
     * Determine if the refresh token has expired, which requires the user to reconnect.
     */
    public function refreshTokenHasExpired(): bool
    {
        return $this->refresh_token_expires_at->isPast();
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
        ];
    }
}
