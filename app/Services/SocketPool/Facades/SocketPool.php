<?php

namespace App\Services\SocketPool\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array sendGpsData(string $gpsData, string $host, int $port, string $vehicleId, array $options = [])
 * @method static array batchSendGpsData(array $gpsDataArray, array $options = [])
 * @method static array getConnectionStats()
 * @method static array getMetrics()
 * @method static array closeConnection(string $host, int $port)
 * @method static array performHealthCheck()
 * @method static array getConfiguration()
 * @method static bool isServiceRunning()
 * @method static array testConnection(string $host, int $port)
 * @method static array getServiceInfo()
 */
class SocketPool extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'socket-pool';
    }
}