<?php

include 'includes/dbFacile.php';
$db = dbFacile::open('mysql', 'my_test_db', 'b4com', 'qwerty', 'localhost');
$id = $db->insert(array('coomand' => 'pwd',), 'taskscheduler');
