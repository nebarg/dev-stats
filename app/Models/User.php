<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Casts\AsCalendarDate;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $api_key
 * @property string $timezone
 * @property int $start_of_week
 * @property int $heartbeat_timeout_sec
 * @property CarbonImmutable|null $summaries_generated_until
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'timezone', 'start_of_week', 'heartbeat_timeout_sec'])]
#[Hidden(['password', 'api_key', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'timezone' => 'UTC',
        'start_of_week' => 1,
        'heartbeat_timeout_sec' => 600,
    ];

    protected static function booted(): void
    {
        static::creating(static function (User $user): void {
            $user->api_key ??= (string) Str::uuid();
        });
    }

    /**
     * @return HasMany<Heartbeat, $this>
     */
    public function heartbeats(): HasMany
    {
        return $this->hasMany(Heartbeat::class);
    }

    /**
     * @return HasMany<Duration, $this>
     */
    public function durations(): HasMany
    {
        return $this->hasMany(Duration::class);
    }

    /**
     * @return HasMany<SummaryItem, $this>
     */
    public function summaryItems(): HasMany
    {
        return $this->hasMany(SummaryItem::class);
    }

    /**
     * @return HasMany<DailyMetric, $this>
     */
    public function dailyMetrics(): HasMany
    {
        return $this->hasMany(DailyMetric::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'start_of_week' => 'integer',
            'heartbeat_timeout_sec' => 'integer',
            'summaries_generated_until' => AsCalendarDate::class,
        ];
    }
}
