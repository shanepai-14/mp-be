<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'driver_name',
        'vehicle_status',
        'contact_no',
        'device_id_plate_no',
        'vendor_id',
        'mileage',
        'register_by_user_id',
        'updated_by_user_id'
    ];

    protected $hidden = [
        // 'created_at',
        // 'updated_at',
        'deleted_at'
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function register_by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'register_by_user_id', 'id');
    }

    public function updated_by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id', 'id');
    }
}
