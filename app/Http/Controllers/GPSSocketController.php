<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Calculation\TextData\Replace;

class GPSSocketController extends Controller
{
    // Submit formatted GPS data to WL server via TCP/IP
    public function submitFormattedGPS($gpsData, $wl_ip, $wl_port, $vehicle_id)
    {
        date_default_timezone_set('UTC');
        $host = $wl_ip ? $wl_ip : "20.195.56.146";
        $port = $wl_port ? $wl_port : 2199;
        $message = $gpsData . "\r";

        // No Timeout
        set_time_limit(0);

        $socket = null;
        $error_message = 'Could not create socket';
        $error_data = [
            "Vehicle" => $vehicle_id,
            "Date" => now()->toISOString(),
            "Host" => $host,
            "Port" => $port,
            "GPS" => $message,
            "Reason" => ""
        ];
        try {
            // create socket
            $socket = socket_create(AF_INET, SOCK_STREAM, 0);

            if ($socket) {
                $error_message = "Could not connect to server";
                // connect to server
                $socket_connect = socket_connect($socket, $host, $port);

                if ($socket_connect) {
                    $error_message = 'Could not send data to server';
                    // send string to server
                    $socket_write = socket_write($socket, $message, strlen($message));
                    if ($socket_write === 0) {
                        $error_data['Reason'] = "No bytes have been written";
                        Log::channel('gpserrorlog')->error($error_data);
                    }
                    else if ($socket_write === false) {
                        $this->catchSocketError($socket, 'socket_write');
                    };

                    $error_message = 'Could not read data';
                    // get server response
                     $socket_read = socket_read($socket, 2048);
                     if ($socket_read) {
                         Log::channel('gpssuccesslog')->info([
                             "Vehicle" => $vehicle_id,
                             "Date" => now()->toISOString(),
                             "Position" => preg_replace('/\s+/', '', $message),
                             "Raw response" => $socket_read,
                             "Hex" => bin2hex($socket_read)
                         ]);
                     }
                     else $this->catchSocketError($socket, 'socket_read');
                }
                else $this->catchSocketError($socket, 'socket_connect');
            }
            else $this->catchSocketError($socket, 'socket_create');
        }
        catch(_) {
            $error_data['Reason'] = $error_message;
            Log::channel('gpserrorlog')->error($error_data);
        }
        finally {
            //The default value of mode in socket_shutdown is 2
            $socket_shutdown = socket_shutdown($socket);
            if ($socket_shutdown === false) {
                $this->catchSocketError($socket, 'socket_shutdown');
            }

            socket_close($socket);
        }
    }

    private function catchSocketError($socket_instance, $socket_func_name = ""): void {
        //Socket will return false on failure
        if ($socket_instance === false) {
            $error = "$socket_func_name failed; reason: " . socket_strerror(socket_last_error());
            socket_clear_error();
        }
        else {
            $error = "$socket_func_name failed; reason: " . socket_strerror(socket_last_error($socket_instance));
            socket_clear_error($socket_instance);
        }

        $error_data['Reason'] = $error;
        Log::channel('gpserrorlog')->error($error_data);
    }
}


