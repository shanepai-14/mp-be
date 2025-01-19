<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Jenssegers\Mongodb\Eloquent\HybridRelations;

/**
 * Class Vehicle.
 *
 * @OA\Schema(
 *     title="Vehicle",
 *     description="Vehicle",
 * )
 */
class Vehicle extends Model
{
    use HybridRelations;
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

    // /**
    //  * @OA\Property(
    //  *     description="Driver Name",
    //  * )
    //  *
    //  * @var string
    //  */
    // private $driver_name;

    // /**
    //  * @OA\Property(
    //  *     description="Vehicle Status Id",
    //  * )
    //  *
    //  * @var string
    //  */
    // private $vehicle_status;

    /**
     * @OA\Property(
     *     description="Device Id/Plate No.",
     * )
     *
     * @var string
     */
    private $device_id_plate_no;

    /**
     * @OA\Property(
     *     property="vendor_id",
     *     description="Transporter Id",
     * )
     *
     * @var string
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

    // /**
    //  * @OA\Property(
    //  *     description="Mileage",
    //  * )
    //  *
    //  * @var string
    //  */
    // private $mileage;

    protected $connection = 'mysql';

    protected $fillable = [
        'device_id_plate_no',
        'transporter_id',
        // 'vehicle_status',
        'driver_name',
        // 'mileage',
        'register_by_user_id',
        'updated_by_user_id'
    ];

    protected $appends = [
        'vendor_id'
    ];

    protected $hidden = [
        // 'created_at',
        // 'updated_at',
        'transporter_id',
        'deleted_at',
    ];

    public function getVendorIdAttribute(){
        return $this->attributes['transporter_id'];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Transporter::class);
    }

    public function register_by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'register_by_user_id', 'id');
    }

    public function updated_by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id', 'id');
    }

    public function Gps(): HasMany
    {
        return $this->hasMany(Gps::class);
    }

    public function vehicleAssignment(): HasMany
    {
        return $this->hasMany(VehicleAssignment::class);
    }
}
