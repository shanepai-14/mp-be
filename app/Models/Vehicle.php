<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'driver_name',
        'is_registered',
        'contact_no',
        'device_id_plate_no',
        'vendor_id',
        'mileage'
    ];

    protected $hidden = [
        'created_at',
        'updated_at'
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
