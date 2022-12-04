<?php


function load_json($json){
	return json_decode(file_get_contents("commands.json"));
}


run_procs($)
$descriptorspec = array(
	0 => array("pipe", "r"),
	1 => array("pipe", "w"),
	2 => array("file", "b4com_error-output.txt", "a")
);
$process = proc_open('sleep 1', $descriptorspec, $pipes);
$read = '';

if (is_resource($process)) {
	fclose($pipes[0]);

	while (!feof($pipes[1])) {
		$read .= fgets($pipes[1], 1024);
	}
	fclose($pipes[1]);
	proc_close($process);
}
