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
     *     description="Vendor API Key"
     * )
     *
     * @var string
     *
     */
    private $CompanyKey;

    /**
     * @OA\Property(
     *     format="string",
     *     description="Vehicle Plate Number. Remove in-between spaces. Alphanumer only.",
     * )
     *
     * @var string
     */
    private $Vehicle_ID;

    /**
     * @OA\Property(
     *     format="datetime",
     *     type="string",
     *     description="Position timeStamp in UTC FORMAT",
     * )
     *
     * @var \DateTime
     */
    private $Timestamp;

    /**
     * @OA\Property(
     *     format="int32",
     *     description="1 - GPS tracker is online. 0 - GPS tracker is offline.",
     * )
     *
     * @var integer
     */
    private $GPS;

    /**
     * @OA\Property(
     *     format="int32",
     *     description="1 - Ignition is ON. 0 - Ignition is OFF",
     * )
     *
     * @var integer
     */
    private $Ignition;

    /**
     * @OA\Property(
     *     format="float",
     *     description="Coordinate of the position data. Precision: 6 decimal places",
     * )
     *
     * @var float
     */
    private $Latitude;

    /**
     * @OA\Property(
     *     format="float",
     *     description="Coordinate of the position data. Precision: 6 decimal places",
     * )
     *
     * @var float
     */
    private $Longitude;

    /**
     * @OA\Property(
     *     format="float",
     *     description="Altitude in meters. Set to 0 if data unavailable.",
     * )
     *
     * @var float
     */
    private $Altitude;

    /**
     * @OA\Property(
     *     format="int32",
     *     description="Range from 0 to 999 in kilometer per hour.",
     * )
     *
     * @var integer
     */
    private $Speed;

    /**
     * @OA\Property(
     *     format="int32",
     *     description="Angle of direction where vehicle is heading in degrees. Range from 0 to 359",
     * )
     *
     * @var integer
     */
    private $Course;

    /**
     * @OA\Property(
     *     format="int32",
     *     description="Number of satellites detected by the tracker. if data unavailable, set to 4 satellites.",
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
     *     description="Device mileage in kilometers",
     * )
     *
     * @var integer
     */
    private $Mileage;

    /**
     * @OA\Property(
     *     format="int32",
     *     description="2 - Unloading, 1 - Mixing, 0 - Stop",
     *     nullable=true
     * )
     *
     * @var integer
     */
    private $Drum_Status;

    /**
     * @OA\Property(
     *     format="int32",
     *     description="Concrete mixer drum rounds per minute.",
     *     nullable=true
     * )
     *
     * @var integer
     */
    private $RPM;

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
