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
        // 'message_id',
        'timestamp',
        'gps',
        'ignition',
        'latitude',
        'longitude',
        'altitude',
        'speed',
        'course',
        'satellite_count',
        'adc1',
        'adc2',
        // 'io_status',
        'drum_status',
        'mileage',
        'rpm',
        'device_id'
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'device_id', 'device_id_plate_no');
    }
}