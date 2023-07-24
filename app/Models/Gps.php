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
 * )
 */
class Gps extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'GPS';

    use HasFactory, SoftDeletes;

    /**
     * @OA\Property(
     *     format="string",
     *     title="Vendor Key",
     * )
     *
     * @var string
     */
    private $CompanyKey;

    /**
     * @OA\Property(
     *     format="datetime",
     *     type="string",
     *     title="TimeStamp",
     * )
     *
     * @var \DateTime
     */
    private $Timestamp;

    /**
     * @OA\Property(
     *     format="int32",
     *     title="GPS Status",
     * )
     *
     * @var integer
     */
    private $GPS;

    /**
     * @OA\Property(
     *     format="int32",
     *     title="Ignition Status",
     * )
     *
     * @var integer
     */
    private $Ignition;

    /**
     * @OA\Property(
     *     format="float",
     *     title="Latitude",
     * )
     *
     * @var float
     */
    private $Latitude;

    /**
     * @OA\Property(
     *     format="float",
     *     title="Longitude",
     * )
     *
     * @var float
     */
    private $Longitude;

    /**
     * @OA\Property(
     *     format="float",
     *     title="Altitude",
     * )
     *
     * @var float
     */
    private $Altitude;

    /**
     * @OA\Property(
     *     format="int32",
     *     title="Speed",
     * )
     *
     * @var integer
     */
    private $Speed;

    /**
     * @OA\Property(
     *     format="int32",
     *     title="Course",
     * )
     *
     * @var integer
     */
    private $Course;

    /**
     * @OA\Property(
     *     format="int32",
     *     title="Satellite_Count",
     * )
     *
     * @var integer
     */
    private $Satellite_Count;

    /**
     * @OA\Property(
     *     format="float",
     *     title="ADC1",
     * )
     *
     * @var float
     */
    private $ADC1;

    /**
     * @OA\Property(
     *     format="float",
     *     title="ADC2",
     * )
     *
     * @var float
     */
    private $ADC2;

    /**
     * @OA\Property(
     *     format="int32",
     *     title="Mileage",
     * )
     *
     * @var integer
     */
    private $Mileage;

    /**
     * @OA\Property(
     *     format="int32",
     *     title="Drum_Status",
     * )
     *
     * @var integer
     */
    private $Drum_Status;

    /**
     * @OA\Property(
     *     format="int32",
     *     title="RPM",
     * )
     *
     * @var integer
     */
    private $RPM;

    /**
     * @OA\Property(
     *     format="string",
     *     title="Device_ID",
     * )
     *
     * @var string
     */
    private $Device_ID;

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
        'Device_ID',     // Vehicle Plate Number
        'Position'
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'Device_ID', 'device_id_plate_no');
    }
}