<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Calculation\TextData\Replace;

class GPSSocketController extends Controller
{
    protected array $error_data = [];

    // Submit formatted GPS data to WL server via TCP/IP
    public function submitFormattedGPS($gpsData, $wl_ip, $wl_port, $vehicle_id)
    {
        date_default_timezone_set('UTC');
        $host = $wl_ip ? $wl_ip : "20.195.56.146";
        $port = $wl_port ? $wl_port : 2199;
        $message = $gpsData . "\r";

        set_time_limit(0);

        $socket = null;
        $error_message = 'Could not create socket';
        $this->error_data = [
            "Vehicle" => $vehicle_id,
            "Date" => now()->toISOString(),
            "Host" => $host,
            "Port" => $port,
            "GPS" => $message,
            "Reason" => ""
        ];

        try {
            // Create socket
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

            if ($socket !== false) {
                // Set send & receive timeouts (2 seconds)
                socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ["sec" => 2, "usec" => 0]);
                socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ["sec" => 2, "usec" => 0]);

                $error_message = "Could not connect to server";

                if (socket_connect($socket, $host, $port)) {
                    $error_message = 'Could not send data to server';
                    $socket_write = socket_write($socket, $message, strlen($message));

                    if ($socket_write === 0) {
                        $this->error_data['Reason'] = "No bytes have been written";
                        Log::channel('gpserrorlog')->error($this->error_data);
                    } elseif ($socket_write === false) {
                        $this->catchSocketError($socket, 'socket_write');
                    }

                    $error_message = 'Could not read data';

                    // OPTIONAL: Read server response (only if needed)
                    $socket_read = socket_read($socket, 2048);
                    if ($socket_read !== false) {
                        Log::channel('gpssuccesslog')->info([
                            "Vehicle" => $vehicle_id,
                            "Date" => now()->toISOString(),
                            "Position" => preg_replace('/\s+/', '', $message),
                            "Raw response" => $socket_read,
                            "Hex" => bin2hex($socket_read)
                        ]);
                    } else {
                        $this->catchSocketError($socket, 'socket_read');
                    }
                } else {
                    $this->catchSocketError($socket, 'socket_connect');
                }
            } else {
                $this->catchSocketError($socket, 'socket_create');
            }
        } catch (\Throwable $e) {
            $this->error_data['Reason'] = $error_message . " | Exception: " . $e->getMessage();
            Log::channel('gpserrorlog')->error($this->error_data);
        } finally {
            if ($socket && is_resource($socket)) {
                $socket_shutdown = socket_shutdown($socket);
                if ($socket_shutdown === false) {
                    $this->catchSocketError($socket, 'socket_shutdown');
                }
                socket_close($socket);
            }
        }
    }

    private function catchSocketError($socket_instance, $socket_func_name = ""): void
    {
        $error = "$socket_func_name failed; reason: " . socket_strerror(socket_last_error($socket_instance));
        socket_clear_error($socket_instance);
        $this->error_data['Reason'] = $error;
        Log::channel('gpserrorlog')->error($this->error_data);
    }
}




