<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class CustomerIpPorts.
 *
 * @OA\Schema(
 *     title="Customer IPs and Ports",
 *     description="Customer`s assigned IPs and Ports",
 *     required={"customer_id", "ip", "port"}
 * )
 */
class CustomerIpPorts extends Model
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
     *     format="int64",
     *     description="Customer ID",
     * )
     *
     * @var integer
     */
    private $customer_id;

     /**
     * @OA\Property(
     *     description="IP address",
     * )
     *
     * @var string
     */
    private $ip;

     /**
     * @OA\Property(
     *     format="int64",
     *     description="Port",
     * )
     *
     * @var integer
     */
    private $port;

    protected $fillable = [
        'customer_id',
        'ip',
        'port',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at'
    ];

     public function customer(): BelongsTo
     {
         return $this->belongsTo(Customer::class, 'customer_id', 'id');
     }
     
    public function currentCustomer(): HasMany
    {
        return $this->hasMany(CurrentCustomer::class);
    }
}