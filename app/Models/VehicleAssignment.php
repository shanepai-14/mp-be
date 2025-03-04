<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class VehicleAssignments.
 *
 * @OA\Schema(
 *     title="VehicleAssignments",
 *     description="VehicleAssignments",
 *     required={"vehicle_id", "vehicle_status", "mileage"}
 * )
 */
class VehicleAssignment extends Model
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
     *     description="Vehicle ID",
     * )
     *
     * @var integer
     */
    private $vehicle_id;

     /**
     * @OA\Property(
     *     description="Vehicle Status Id",
     * )
     *
     * @var string
     */
    private $vehicle_status;

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
     *     description="Driver name",
     * )
     *
     * @var string
     */
    private $driver_name;

    /**
     * @OA\Property(
     *     description="Transporter Code",
     * )
     *
     * @var string
     */
    private $transporter_code;


     /**
     * @OA\Property(
     *     format="int64",
     *     description="Mileage",
     * )
     *
     * @var integer
     */
    private $mileage;

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
        'vehicle_id',
        'vehicle_status',
        'driver_name',
        'transporter_code',
        'mileage',
        'customer_code',
        'register_by_user_id',
        'updated_by_user_id'
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id', 'id');
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
