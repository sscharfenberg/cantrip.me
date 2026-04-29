<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\DeckSort;
use App\Enums\DeckView;
use App\Enums\Locale;
use App\Notifications\PasswordResetLinkNotification;
use App\Notifications\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Features;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, TwoFactorAuthenticatable;

    const NAME_MIN = 8;

    const NAME_MAX = 80;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'locale',
        'currency',
        'deck_view_default',
        'deck_sort_default',
        'collection_integration_enabled',
        'email_verified_at',
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'locale' => Locale::class,
            'currency' => Currency::class,
            'deck_view_default' => DeckView::class,
            'deck_sort_default' => DeckSort::class,
            'collection_integration_enabled' => 'boolean',
        ];
    }

    /**
     * Get the user's preferred locale for the HasLocalePreference interface.
     */
    public function preferredLocale(): string
    {
        return $this->locale->value;
    }

    /**
     * Send the email verification notification, if the feature is enabled.
     */
    public function sendEmailVerificationNotification(): void
    {
        if (! Features::enabled(Features::emailVerification())) {
            return;
        }

        $this->notify(new VerifyEmailNotification);
    }

    /**
     * Send the password reset notification.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new PasswordResetLinkNotification($token));
    }

    /**
     * Get the containers belonging to this user.
     *
     * @return HasMany<Container>
     */
    public function containers(): HasMany
    {
        return $this->hasMany(Container::class);
    }

    /**
     * Get the card stacks belonging to this user.
     *
     * @return HasMany<CardStack>
     */
    public function cardStacks(): HasMany
    {
        return $this->hasMany(CardStack::class);
    }

    /**
     * Get the decks belonging to this user.
     *
     * @return HasMany<Deck>
     */
    public function decks(): HasMany
    {
        return $this->hasMany(Deck::class);
    }
}
