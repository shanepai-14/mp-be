<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Calculation\TextData\Replace;

class GPSSocketController extends Controller
{
    // Submit formatted GPS data to WL server via TCP/IP
    public function submitFormattedGPS($gpsData, $wl_ip, $wl_port)
    {
        $host = $wl_ip ? $wl_ip : "20.195.56.146";
        $port = $wl_port ? $wl_port : 2199;
        $message = $gpsData . "\r";
      
        // No Timeout 
        set_time_limit(0);

        // create socket
        $socket = socket_create(AF_INET, SOCK_STREAM, 0) or die("Could not create socket\n");

        // connect to server
        $result = socket_connect($socket, $host, $port) or die("Could not connect to server\n");

        // send string to server
        socket_write($socket, $message, strlen($message)) or die("Could not send data to server\n");

        // get server response
        // $result = socket_read($socket, 1024) or die("Could not read server response\n");

        // echo "Reply From Server  :" . $result;
        // close sockets
        socket_close($socket);
    }
}


