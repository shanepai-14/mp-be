<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class Transporter.
 *
 * @OA\Schema(
 *     title="Transporter",
 *     description="Transporter",
 * )
 */
class Transporter extends Model
{
    use HasFactory, SoftDeletes;

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
     *     description="Transporter Name",
     * )
     *
     * @var string
     */
    private $transporter_name;

    /**
     * @OA\Property(
     *     description="Transporter Address",
     * )
     *
     * @var string
     */
    private $transporter_address;

    /**
     * @OA\Property(
     *     description="Transporter Contact no.",
     * )
     *
     * @var string
     */
    private $transporter_contact_no;

    /**
     * @OA\Property(
     *     format="email",
     *     description="Transporter Email",
     * )
     *
     * @var string
     */
    private $transporter_email;

    /**
     * @OA\Property(
     *     description="Transporter code",
     * )
     *
     * @var string
     */
    private $transporter_code;

    /**
     * @OA\Property(
     *     description="Transporter Key",
     * )
     *
     * @var string
     */
    private $transporter_key;

    protected $fillable = [
        'transporter_name',
        'transporter_address',
        'transporter_contact_no',
        'transporter_email',
        'transporter_code',
        'transporter_key'
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    public function user(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function vehicle(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function customer(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}