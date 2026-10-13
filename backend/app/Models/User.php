<?php

namespace App\Models;

use App\Enums\NotificationCategory;
use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'phone', 'email', 'password', 'locale'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    protected $attributes = [
        'status' => 'active',
        'locale' => 'en',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'suspended_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'notification_preferences' => 'array',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->withTimestamps()->orderBy('roles.slug');
    }

    public function farms(): BelongsToMany
    {
        return $this->belongsToMany(Farm::class, 'farm_members')->withPivot('role')->withTimestamps();
    }

    /**
     * Default address first.
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(UserAddress::class)->orderByDesc('is_default')->orderBy('created_at');
    }

    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'buyer_id');
    }

    public function favourites(): HasMany
    {
        return $this->hasMany(Favourite::class);
    }

    /**
     * Marketplace staff: may see unapproved farms and other moderation data.
     */
    public function isStaff(): bool
    {
        return $this->hasRole(RoleSlug::SuperAdmin, RoleSlug::Admin, RoleSlug::SupportAgent);
    }

    public function hasRole(RoleSlug ...$roles): bool
    {
        $slugs = array_map(fn (RoleSlug $role) => $role->value, $roles);

        return $this->roles->whereIn('slug', $slugs)->isNotEmpty();
    }

    public function assignRole(RoleSlug $role): void
    {
        $this->roles()->syncWithoutDetaching([Role::idFor($role)]);
        $this->unsetRelation('roles');
    }

    public function removeRole(RoleSlug $role): void
    {
        $this->roles()->detach(Role::idFor($role));
        $this->unsetRelation('roles');
    }

    public function isSuspended(): bool
    {
        return $this->status === UserStatus::Suspended;
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }

    public function markPhoneAsVerified(): void
    {
        $this->forceFill(['phone_verified_at' => now()])->save();
    }

    public function wantsSms(NotificationCategory $category): bool
    {
        return ($this->notification_preferences['sms'][$category->value] ?? true) === true;
    }

    /**
     * Where alerts that leave the app go: "sms" (the default) or "whatsapp". The per-category
     * switches apply to either.
     */
    public function alertChannel(): string
    {
        return ($this->notification_preferences['channel'] ?? 'sms') === 'whatsapp' ? 'whatsapp' : 'sms';
    }

    public function prefersWhatsApp(): bool
    {
        return $this->alertChannel() === 'whatsapp';
    }

    /**
     * Every alert setting, defaults included.
     *
     * @return array{sms: array<string, bool>, channel: string}
     */
    public function notificationSettings(): array
    {
        $sms = [];

        foreach (NotificationCategory::withSms() as $category) {
            $sms[$category->value] = $this->wantsSms($category);
        }

        return ['sms' => $sms, 'channel' => $this->alertChannel()];
    }
}
