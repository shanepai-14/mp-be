<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;

/**
 * Class User.
 *
 * @OA\Schema(
 *     title="User",
 *     description="User",
 *     required={"username_email", "password", "full_name", "vendor_id", "user_role"}
 * )
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @OA\Property(
     *     format="int64",
     *     description="ID",
     * )
     *
     * @var integer
     */
    private $id;

    /**
     * @OA\Property(
     *     format="email",
     *     description="Email",
     * )
     *
     * @var string
     */
    private $username_email;

     /**
     * @OA\Property(
     *     format="int64",
     *     description="Password",
     *     maximum=255
     * )
     *
     * @var string
     */
    private $password;

    /**
     * @OA\Property(
     *     description="Full name",
     * )
     *
     * @var string
     */
    private $full_name;

    /**
     * @OA\Property(
     *     format="int64",
     *     description="Vendor ID",
     * )
     *
     * @var integer
     */
    private $vendor_id;

     /**
     * @OA\Property(
     *     format="msisdn",
     *     description="Phone",
     * )
     *
     * @var string
     */
    private $contact_no;

    /**
     * @OA\Property(
     *     format="int64",
     *     description="User Role ID",
     * )
     *
     * @var integer
     */
    private $user_role;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'username_email',
        'password',
        'full_name',
        'vendor_id',
        'contact_no',
        'user_role',
        'first_login'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'created_at',
        'updated_at'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    protected $attributes = [ 'user_role' => 'user' ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function vehicle_register_by(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function vehicle_updated_by(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }
}