<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class Customer.
 *
 * @OA\Schema(
 *     title="Customer",
 *     description="Customer",
 *     required={"transporter_id", "customer_name", "customer_code"}
 * )
 */
class Customer extends Model
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
     *     description="Customer name",
     * )
     *
     * @var string
     */
    private $customer_name;

     /**
     * @OA\Property(
     *     description="Customer Address",
     * )
     *
     * @var string
     */
    private $customer_address;

    /**
     * @OA\Property(
     *     description="Customer Contact no.",
     * )
     *
     * @var string
     */
    private $customer_contact_no;

    /**
     * @OA\Property(
     *     format="email",
     *     description="Customer Email",
     * )
     *
     * @var string
     */
    private $customer_email;

    /**
     * @OA\Property(
     *     description="Customer code",
     * )
     *
     * @var string
     */
    private $customer_code;

     /**
     * @OA\Property(
     *     property="vendor_id",
     *     format="int64",
     *     description="Transporter ID",
     * )
     *
     * @var integer
     */
    private $transporter_id;

    /**
     * @OA\Property(
     *     format="int64",
     *     description="Register By UserId",
     * )
     *
     * @var integer
     */
    private $register_by_user_id;

    /**
     * @OA\Property(
     *     format="int64",
     *     description="Updated By UserId",
     * )
     *
     * @var integer
     */
    private $updated_by_user_id;

    protected $fillable = [
        'customer_name',
        'customer_address',
        'customer_contact_no',
        'customer_email',
        'customer_code',
        'transporter_id',
        'register_by_user_id',
        'updated_by_user_id'
    ];

    protected $appends = [
        'vendor_id'
    ];

    protected $hidden = [
        'transporter_id',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    public function getVendorIdAttribute(){
        return $this->attributes['transporter_id'];
    }

    // *NOTE: VENDOR === TRANSPORTER
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Transporter::class, 'transporter_id', 'id');
    }

    public function customerIpPort(): HasMany
    {
        return $this->hasMany(CustomerIpPorts::class);
    }

    public function register_by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'register_by_user_id', 'id');
    }

    public function updated_by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id', 'id');
    }

    public function currentCustomer(): HasMany
    {
        return $this->hasMany(CurrentCustomer::class);
    }
}
