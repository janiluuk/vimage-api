<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Tymon\JWTAuth\Contracts\JWTSubject;
use App\Exceptions\ConstraintException;
use App\Models\Videojob;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Notifications\Notifiable;
use App\Notifications\VerifyNotification;
use App\Notifications\BindEmailNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\MediaLibrary\InteractsWithMedia;

class User extends Authenticatable implements JWTSubject, HasMedia
{
    use HasFactory, HasRoles, Notifiable, InteractsWithMedia;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */

    protected $fillable = [
        'login',
        'email',
        'license',
        'profile_image',
        'online',
        'confirm_send_email',
        'password_reset_admin',
        'user_role_id',
        'id',
        'google_id',
        'facebook_id',
        'stripe_id',
        'balance',
        'discord_id'
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    protected function preview(): Attribute
    {
        return Attribute::get(fn () => $this->hasMedia('user') ? $this->getFirstMediaUrl('user') : url('static/not-found.svg'));
    }

    public function emailToken(): HasOne
    {
        return $this->hasOne(EmailToken::class);
    }


    public function wallet(): HasOne
    {
        return $this->hasOne(UserWallet::class);
    }


    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function chats()
    {
        // Return chats where user is either first or second participant
        return Chat::where('first_user_id', $this->id)
            ->orWhere('second_user_id', $this->id);
    }
    
    // Alias for backwards compatibility
    public function chat()
    {
        return $this->chats();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'author_user_id');
    }

    public function userRole(): BelongsTo
    {
        return $this->BelongsTo(UserRole::class);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getLogin(): string
    {
        return $this->login;
    }
    public function getProfileImage(): ?string
    {
        return $this->profile_image;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getLicense(): int
    {
        return $this->license;
    }

    public function getOnline(): int
    {
        return $this->online;
    }

    public function getConfirmSendEmail(): int
    {
        return $this->confirm_send_email;
    }

    public function getPasswordResetAdmin(): bool
    {
        return $this->password_reset_admin;
    }

    public function getUserRoleId(): int
    {
        return $this->user_role_id;
    }

    public function getCreatedAt(): string
    {
        return $this->created_at;
    }

    public function getUpdatedAt(): string
    {
        return $this->updated_at;
    }

    public function getBalance(): string
    {
        return $this->balance;
    }

    
    public function getStripeId(): ?string
    {
        return $this->stripe_id;
    }
    public function getGoogleId(): ?string
    {
        return $this->google_id;
    }
    public function getFacebookId(): ?string
    {
        return $this->facebook_id;
    }

    public function getDiscordId(): ?string
    {
        return $this->discord_id;
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [];
    }

    /**
     * A User has many items
     *
     * @return HasMany
     */
    public function items()
    {
        return $this->hasMany(Item::class);
    }

    /**
     * A User has many items
     *
     * @return HasMany
     */
    public function videoJobs()
    {
        return $this->hasMany(Videojob::class);
    }

    /**
     * A User has many orders
     *
     * @return HasMany
     */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * A User has many finance operations
     *
     * @return HasMany
     */
    public function financeOperations()
    {
        return $this->hasMany(FinanceOperationsHistory::class);
    }

    /**
     * A User has many support requests
     *
     * @return HasMany
     */
    public function supportRequests()
    {
        return $this->hasMany(SupportRequest::class, 'author_id');
    }

    /**
     * Get data statistics for the user
     *
     * @return array
     */
    public function getDataStats(): array
    {
        return [
            'products_count' => $this->products()->count(),
            'video_jobs_count' => $this->videoJobs()->count(),
            'items_count' => $this->items()->count(),
            'messages_count' => $this->messages()->count(),
            'chats_count' => $this->chat()->count(),
            'orders_count' => $this->orders()->count(),
            'finance_operations_count' => $this->financeOperations()->count(),
            'support_requests_count' => $this->supportRequests()->count(),
            'media_count' => $this->getMedia()->count(),
        ];
    }

    public function sendBindEmail(string $token, string $email)
    {
        $this->notify(new BindEmailNotification($token, $email));
    }

    public function sendEmailVerificationNotification()
    {
        $this->notify(new VerifyNotification());
    }

    /**
     * Send the password reset notification.
     *
     * @param string $token
     * @return void
     */
    public function sendPasswordResetNotification($token)
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * @param $query
     * @param $name
     * @return mixed
     */
    public function scopeName($query, $name)
    {
        return $query->where('users.name', 'LIKE', "%$name%", 'or');
    }

    /**
     * @param $query
     * @param $email
     * @return mixed
     */
    public function scopeEmail($query, $email)
    {
        return $query->where('users.email', 'LIKE', "%$email%", 'or');
    }

    /**
     * @param $query
     * @param $role
     * @return mixed
     */
    public function scopeRoles($query, $role)
    {
        return $query->orWhereHas('roles', function ($query) use ($role) {
            $query->where('roles.name', 'LIKE', "%$role%");
        });
    }

    /**
     * @return bool|null
     * @throws ConstraintException
     */
    public function delete()
    {
        if ($this->id == auth()->id()) {
            throw new ConstraintException('You cannot delete yourself.');
        }

        return parent::delete();
    }

}
