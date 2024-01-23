<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class CurrentCustomer.
 *
 * @OA\Schema(
 *     title="CurrentCustomer",
 *     description="CurrentCustomer",
 *     required={"vehicle_assignment_id", "customer_id", "ipport_id"}
 * )
 */
class CurrentCustomer extends Model
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
     *     format="int64",
     *     description="Vehicle Assignment ID",
     * )
     *
     * @var integer
     */
    private $vehicle_assignment_id;

    /**
     * @OA\Property(
     *     format="int64",
     *     description="Customer ID",
     * )
     *
     * @var integer
     */
    private $customer_id;

    /**
     * @OA\Property(
     *     format="int64",
     *     description="IP and Port ID",
     * )
     *
     * @var integer
     */
    private $ipport_id;

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
        'vehicle_assignment_id',
        'customer_id',
        'ipport_id',
        'register_by_user_id',
        'updated_by_user_id'
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    public function vehicleAssignment(): BelongsTo
    {
        return $this->belongsTo(VehicleAssignment::class, 'vehicle_assignment_id', 'id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function ipport(): BelongsTo
    {
        return $this->belongsTo(CustomerIpPorts::class, 'ipport_id', 'id');
    }
}