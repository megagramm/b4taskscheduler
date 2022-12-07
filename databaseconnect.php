<?php
$host = 'localhost';
$dbname = 'b4com';
$username = 'b4com';
$password = 'qwerty';

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    echo "Connected to $dbname at $host successfully.";
} catch (PDOException $pe) {
    die("Could not connect to the database $dbname :" . $pe->getMessage());
}

$res = $conn->query("SELECT * FROM taskscheduler");
while ($row = $res->fetch()) {
    echo $row['command'] . "<br />\n";
}

// $start =  microtime(true); usleep (1000000); $stop = microtime(true); $duration = $stop - $start; echo PHP_EOL.$start.PHP_EOL.$stop.PHP_EOL.$duration.PHP_EOL;
