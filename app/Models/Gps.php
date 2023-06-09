<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Jenssegers\Mongodb\Eloquent\Model;
use Jenssegers\Mongodb\Eloquent\SoftDeletes;


class Gps extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'GPS';

    use HasFactory, SoftDeletes;

    protected $fillable = [
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