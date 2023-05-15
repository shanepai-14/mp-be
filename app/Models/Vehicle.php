<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
}
