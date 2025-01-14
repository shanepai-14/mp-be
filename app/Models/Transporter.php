<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class Transporter.
 *
 * @OA\Schema(
 *     title="Transporter",
 *     description="Transporter",
 * )
 */
class Transporter extends Model
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
     *     property="vendor_name",
     *     description="Vendor Name",
     * )
     *
     * @var string
     */
    private $transporter_name;

    /**
     * @OA\Property(
     *     property="vendor_address",
     *     description="Vendor Address",
     * )
     *
     * @var string
     */
    private $transporter_address;

    /**
     * @OA\Property(
     *     property="vendor_contact_no",
     *     description="Vendor Contact no.",
     * )
     *
     * @var string
     */
    private $transporter_contact_no;

    /**
     * @OA\Property(
     *     property="vendor_email",
     *     format="email",
     *     description="Vendor Email",
     * )
     *
     * @var string
     */
    private $transporter_email;

    /**
     * @OA\Property(
     *     property="vendor_code",
     *     description="Vendor code",
     * )
     *
     * @var string
     */
    private $transporter_code;

    /**
     * @OA\Property(
     *     property="vendor_key",
     *     description="Vendor Key",
     * )
     *
     * @var string
     */
    private $transporter_key;

    protected $fillable = [
        'transporter_name',
        'transporter_address',
        'transporter_contact_no',
        'transporter_email',
        'transporter_code',
        'transporter_key'
    ];

    protected $appends = [
        'vendor_name',
        'vendor_address',
        'vendor_contact_no',
        'vendor_email',
        'vendor_code',
        // 'vendor_key',
    ];

    protected $hidden = [
        'transporter_name',
        'transporter_address',
        'transporter_contact_no',
        'transporter_email',
        'transporter_code',
        'transporter_key',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    public function getVendorNameAttribute(){
        return $this->attributes['transporter_name'];
    }

    public function getVendorAddressAttribute(){
        return $this->attributes['transporter_address'];
    }

    public function getVendorContactNoAttribute(){
        return $this->attributes['transporter_contact_no'];
    }

    public function getVendorEmailAttribute(){
        return $this->attributes['transporter_email'];
    }

    public function getVendorCodeAttribute(){
        return $this->attributes['transporter_code'];
    }

    public function getVendorKeyAttribute(){
        return $this->attributes['transporter_key'];
    }


    public function user(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function vehicle(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function customer(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
