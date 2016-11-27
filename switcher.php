#!/usr/bin/php
<?php
require "config.php";

if (!isset($bench)) {
	$bench = json_decode(file_get_contents("benchmark.json"), true);
	if ($bench === null) die("No benchmarks available.");
}

$current = -1;
$pd = false;
$ds = array(0 => array("pipe", "r"), 1 => array("file", readlink("/dev/stdout"), "w"), 2 => array("file", readlink("/dev/stderr"), "w"));
array_shift($argv);

while (true) {
	echo "Fetching coin info...\n";
	$curl = curl_init("https://www.nicehash.com/api?method=simplemultialgo.info");
	if (!$curl) die("curl_init failed.");
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	$data = curl_exec($curl);
	curl_close($curl);
	if ($data === false) {
		echo "Error fetching data: ".curl_error($curl)."\n";
		sleep(30 + mt_rand(0,6) - mt_rand(0,6));
		continue;
	}
	$json = json_decode($data);
	if (!isset($json->result) || !isset($json->result->simplemultialgo)) {
		echo "Error decoding data: $data\n";
		sleep(30 + mt_rand(0,6) - mt_rand(0,6));
		continue;
	}

	$best = array();
	foreach ($json->result->simplemultialgo as $a) {
		if (!isset($algos[$a->algo])) { echo "*** New algorithm detected: $a->name ($a->algo) ***\n"; continue; }
		if ($algos[$a->algo] === false) continue;
		$id = $a->algo;
		$algname = $a->name;
		$algo = $algos[$id];
		//         BTC/GHps/day * KHps = uBTC/day
		$pay = round($a->paying * ($bench[$id]/1000), 3);
		if ($pay != 0) $best["$pay"] = array($id, $algname, $algo, $pay);
	}
	krsort($best);
	foreach ($best as $k=>$v) {
		echo "$v[1] ($v[2]): $v[3] uBTC/day\n";
	}
	$info = array_values($best)[0];
	
	if ($info[0] != $current) {
		if ($pd !== false) {
			echo "Terminating running miner...\n";
			proc_terminate($pd);
			proc_close($pd);
		}
	
		if ($info[0] == 999) { // Ethereum
			$o = "http://ethereum.$region.nicehash.com:3500/n1c3-$btcaddr.$name/1";
			$cmd = "$info[2] --farm $o";
		} else if ($info[0] == 24) { // EquiHash
			$cmd = "$info[2] -l $region -u $btcaddr $args";
		} else if ($info[0] == 20) { // DaggerHashimoto
			$o = "stratum+tcp://$info[1].$region.nicehash.com:".(3333+$info[0]);
			$cmd = "$info[2] -S $o -O $btcaddr.$name:x $args ".implode(" ", $argv);
		} else {
			$o = "stratum+tcp://$info[1].$region.nicehash.com:".(3333+$info[0]);
			$cmd = "$info[2] -o $o -O $btcaddr.$name:x $args ".implode(" ", $argv);
		}
	
		echo "Running: $cmd\n";
		$pd = proc_open($cmd, $ds, $pipes);
		if ($pd === false) exit;
		fclose($pipes[0]);
		$current = $info[0];
	}
	
	sleep(60 + mt_rand(0,10) - mt_rand(0,10));
}

?>
