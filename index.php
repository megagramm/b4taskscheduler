<?php
// require __DIR__.'/'.'b4taskscheduler.inc.php';
include(__DIR__.'/'.'b4taskscheduler.inc.php');

class Ttt extends B4TaskScheduler
{
}

$file = 'commands.json';
$job = new Ttt;
// $job = new B4TaskScheduler;
$job->get_process_runing_method();

// $job->load_from_json($file);
$job->load_from_db();
$job->run_procs();
