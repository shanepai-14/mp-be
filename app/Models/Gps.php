<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Jenssegers\Mongodb\Eloquent\Model;
use Jenssegers\Mongodb\Eloquent\SoftDeletes;

/**
 * Class Gps.
 *
 * @OA\Schema(
 *     title="Position",
 *     description="Position",
 *     required={"CompanyKey", "Timestamp", "GPS", "Ignition", "Latitude", "Longitude", "Altitude", "Speed",
 *              "Course", "Satellite_Count", "ADC1", "ADC2", "Mileage", "Vehicle_ID"}
 * )
 */
class Gps extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'GPS';

    use HasFactory, SoftDeletes;

    /**
     * @OA\Property(
     *     property="CompanyKey",
     *     format="string",
     *     description="Vendor Key or Company Key"
     * )
     *
     * @var string
     * 
     */
    private $CompanyKey;

    /**
     * @OA\Property(
     *     format="datetime",
     *     type="string",
     *     description="TimeStamp in UTC FORMAT",
     * )
     *
     * @var \DateTime
     */
    private $Timestamp;

    /**
     * @OA\Property(
     *     format="int32",
     *     description="1 - GPS tracker is online, 0 - GPS tracker is offline",
     * )
     *
     * @var integer
     */
    private $GPS;

    /**
     * @OA\Property(
     *     format="int32",
     *     description="1 - ON, 0 - OFF",
     * )
     *
     * @var integer
     */
    private $Ignition;

    /**
     * @OA\Property(
     *     format="float",
     *     description="Range: -90.0° to 90.0°, Decimal degree: up to 6th decimal point",
     * )
     *
     * @var float
     */
    private $Latitude;

    /**
     * @OA\Property(
     *     format="float",
     *     description="Range: -180.0° to 180.0°, Decimal degree: up to 6th decimal point",
     * )
     *
     * @var float
     */
    private $Longitude;

    /**
     * @OA\Property(
     *     format="float",
     *     description="Integer in meter",
     * )
     *
     * @var float
     */
    private $Altitude;

    /**
     * @OA\Property(
     *     format="int32",
     *     description="Integer in km/h. Range 0 to 999",
     * )
     *
     * @var integer
     */
    private $Speed;

    /**
     * @OA\Property(
     *     format="int32",
     *     description="Integer in degree. Range 0 to 359",
     * )
     *
     * @var integer
     */
    private $Course;

    /**
     * @OA\Property(
     *     format="int32",
     *     description="Number of satellites",
     * )
     *
     * @var integer
     */
    private $Satellite_Count;

    /**
     * @OA\Property(
     *     format="float",
     *     description="Device battery in volts. Minimum value: 0",
     * )
     *
     * @var float
     */
    private $ADC1;

    /**
     * @OA\Property(
     *     format="float",
     *     description="Vehicle battery in volts. Minimum value: 0",
     * )
     *
     * @var float
     */
    private $ADC2;

    /**
     * @OA\Property(
     *     format="int32",
     *     description="Device mileage in KM",
     * )
     *
     * @var integer
     */
    private $Mileage;

    /**
     * @OA\Property(
     *     format="int32",
     *     description="1 - Unloading, 0 - Mixing",
     *     nullable=true
     * )
     *
     * @var integer
     */
    private $Drum_Status;

    /**
     * @OA\Property(
     *     format="int32",
     *     description="Mixer drum RPM counter",
     *     nullable=true
     * )
     *
     * @var integer
     */
    private $RPM;

    /**
     * @OA\Property(
     *     format="string",
     *     description="Unique identifier for the tracker device, e.g. vehicle plate",
     * )
     *
     * @var string
     */
    private $Vehicle_ID;

    protected $fillable = [
        'Vendor_Key',
        'Timestamp',
        'GPS',
        'Ignition',
        'Latitude',
        'Longitude',
        'Altitude',
        'Speed',
        'Course',
        'Satellite_Count',
        'ADC1',
        'ADC2',
        'Mileage',
        'Drum_Status',  // nullable
        'RPM',          // nullable
        'Vehicle_ID',     // Vehicle Plate Number
        'Position'
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'Vehicle_ID', 'device_id_plate_no');
    }
}