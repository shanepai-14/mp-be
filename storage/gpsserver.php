<?php

$host = "127.0.0.1";    // server IP
$port = 6373;           // server Port

echo "Server Running!\n\n";

// No Timeout 
set_time_limit(0);

// create socket
$socket = socket_create(AF_INET, SOCK_STREAM, 0) or die("Could not create socket\n");

// bind socket to port
$result = socket_bind($socket, $host, $port) or die("Could not bind to socket\n");

// start listening for connections
$result = socket_listen($socket, 3) or die("Could not set up socket listener\n");

$isAlive = true;
while ($isAlive) {
    // accept incoming connections
    // spawn another socket to handle communication
    $spawn = socket_accept($socket) or die("Could not accept incoming connection\n");

    // read client input
    $input = socket_read($spawn, 1024) or die("Could not read input\n");
    
    // clean up input string
    $input = trim($input);

    if($input == "stop") {
         $isAlive = false;
         break;
    }

    // Project Path of the Management API
    $path = '/Users/LOG1C/Documents/LOG1C Files/System Development/Project Files/REACT Project/Athena/w-locate GPS BE/management-api';	
   
    exec('cd /management-api && php artisan command:receivegps ' .$input);
}


// close sockets
socket_close($spawn);
socket_close($socket);

?>