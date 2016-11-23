#!/usr/bin/php
<?php
require "config.php";
$bench = array();

function benchmark($id, $algo) {
	global $args;
	
	echo "Benchmarking $algo...\n";
	$count = 0;
	$total = 0;
	$first = true;

	if ($id == 24) { // EquiHash
		$cmd = "$algo -b $args";
	} else {
		$cmd = "$algo --benchmark $args";
	}
	$ds = array(0 => array("pipe", "r"), 1 => array("pipe", "w"), 2 => array("pipe", "w"));
	$pd = proc_open($cmd, $ds, $pipes);
	if ($pd === false) {
		echo "Execution failed.\n";
		continue;
	}
	fclose($pipes[0]);
	$timeouts = 0;
	$line = "";
	while (true) {
		$read = array($pipes[1], $pipes[2]);
		$c = stream_select($read, $write, $except, 30);
		if ($c === false) break;
		if ($c == 0) {
			echo "Timeout $timeouts\n";
			if ($timeouts++ > 10) break;
			continue;
		}
		
		//echo (isset($read[1])?"1":"-") . (isset($read[2])?"2":"-") . "\r";
		$r = array_shift($read);
		if (feof($r)) {
			echo "Program exited.\n";
			break;
		}
		$c = fgetc($r);
		if ($c === false) continue;
		$timeouts = 0;
		$line .= $c;
		if ($c != "\n") continue;
		
		echo "\t$line";
		
		// [2015-12-09 02:20:16] Total: 48.646 H/s
		if (preg_match('|Total: ([\d\.]+) ([kMG]?H)/s|', $line, $matches)) {
			if ($first) { $first = false; continue; }
			echo "\t$matches[1] $matches[2]\n";
			switch ($matches[2]) {
			case 'GH': $matches[1] *= 1000;
			case 'MH': $matches[1] *= 1000;
			case 'kH': $matches[1] *= 1000;
			}
			$total += $matches[1];
			if ($count++ == 5) break;
		} else if (preg_match('|Total: ([\d\.]+) hash/s|', $line, $matches)) {
			if ($first) { $first = false; continue; }
			echo "\t$matches[1] H\n";
			$total += $matches[1];
			if ($count++ == 5) break;
		} else if (preg_match("|Trial \d\.\.\. (\d+)|", $line, $matches)) {
			$total += $matches[1];
			echo "\t$matches[1] H\n";
			if ($count++ == 5) break;
		} else if (preg_match("|Theoretical: ([\d+\.]+) Sols/s|", $line, $matches)) {
			$total = $matches[1];
			$count = 1;
			echo "\t$matches[1] Sols/s\n";
			break;
		} else if (strpos($line, "FATAL") !== false || strpos($line, "Err") !== false) {
			echo "\tError running benchmark.\n";
			break;
		}
		$line = "";
	}
	fclose($pipes[1]);
	fclose($pipes[2]);
	unset($read);
	unset($pipes);
	proc_terminate($pd);
	proc_close($pd);
	
	if ($count == 0) $avg = 0;
	else $avg = $total / $count;
	
	echo "\tAverage: $avg H/s\n";
	return $avg;
}

if ($argc > 1) {
	$bench = json_decode(file_get_contents("benchmark.json"), true);
	array_shift($argv);
	foreach ($argv as $id) {
		$algo = $algos[$id];
		if ($algo === false) continue;
		$bench[$id] = benchmark($id, $algo);
		sleep(5);
	}
} else {
	foreach ($algos as $id=>$algo) {
		if ($algo === false) continue;
		$bench[$id] = benchmark($id, $algo);
		sleep(5);
	}
}

file_put_contents("benchmark.json", json_encode($bench, JSON_PRETTY_PRINT));
