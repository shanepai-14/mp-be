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

        $error_message = 'Could not create socket';
        try {
            // create socket
            $socket = socket_create(AF_INET, SOCK_STREAM, 0) or die("Could not create socket\n");

            $error_message = "Could not connect to server";
            // connect to server
            socket_connect($socket, $host, $port) or die("Could not connect to server\n");

            $error_message = 'Could not send data to server';
            // send string to server
            socket_write($socket, $message, strlen($message)) or die("Could not send data to server\n");

            // get server response
            // $result = socket_read($socket, 1024) or die("Could not read server response\n");

            // echo "Reply From Server  :" . $result;

            $error_message = 'Closing socket';
            // close sockets
            socket_close($socket);
        }
        catch(_) {
            Log::channel('gpserrorlog')->error([
                "Vehicle" => $vehicle_id,
                "Date" => now()->toISOString(),
                "Host" => $host,
                "Port" => $port,
                "GPS" => $message,
                "Reason" => $error_message
            ]);
        }
    }
}


