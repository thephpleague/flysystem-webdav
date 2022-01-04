<?php

$tries = 0;
start:
$tries++;
$success = @fsockopen('localhost', 80);

if ($success) {
    fwrite(STDOUT, "Connected successfully.\n");
    exit(0);
}

if ($tries > 10) {
    fwrite(STDOUT, "Failed to connect.\n");
    exit(1);
}

sleep(1);
fwrite(STDOUT, "Waiting for a connection...\n");
goto start;
