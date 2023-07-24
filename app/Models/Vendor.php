<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class Vendor.
 *
 * @OA\Schema(
 *     title="Vendor",
 *     description="Vendor",
 * )
 */
class Vendor extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * @OA\Property(
     *     format="int64",
     *     title="ID",
     * )
     *
     * @var integer
     */
    private $id;

    /**
     * @OA\Property(
     *     title="Vendor Name",
     * )
     *
     * @var string
     */
    private $vendor_name;

    /**
     * @OA\Property(
     *     title="Vendor Address",
     * )
     *
     * @var string
     */
    private $vendor_address;

    /**
     * @OA\Property(
     *     title="Vendor Contact no.",
     * )
     *
     * @var string
     */
    private $vendor_contact_no;

    /**
     * @OA\Property(
     *     format="email",
     *     title="Vendor Email",
     * )
     *
     * @var string
     */
    private $vendor_email;

    /**
     * @OA\Property(
     *     title="Vendor Contact no.",
     * )
     *
     * @var string
     */
    private $vendor_code;

    /**
     * @OA\Property(
     *     title="Vendor Key",
     * )
     *
     * @var string
     */
    private $vendor_key;

    protected $fillable = [
        'vendor_name',
        'vendor_address',
        'vendor_contact_no',
        'vendor_email',
        'vendor_code',
        'vendor_key'
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
}