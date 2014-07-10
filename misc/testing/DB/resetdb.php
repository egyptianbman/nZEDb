<?php
require_once dirname(__FILE__) . '/../../../www/config.php';

use nzedb\db\Settings;

passthru('clear');
$c = new ColorCLI();

if (!isset($argv[1]) || (isset($argv[1]) && $argv[1] !== 'true'))
	exit($c->error("\nThis script removes all releases and release related files. To run:\nphp resetdb.php true\n"));

echo $c->warning("This script removes all releases, nzb files, samples, previews , nfos, truncates all article tables and resets all groups.");
echo $c->header("Are you sure you want reset the DB?  Type 'DESTROY' to continue:  \n");
echo $c->warningOver("\n");
$line = fgets(STDIN);
if (trim($line) != 'DESTROY')
	exit($c->error("This script is dangerous you must type DESTROY for it function."));

echo "\n";
echo $c->header("Thank you, continuing...\n\n");

$pdo = new Settings();
$timestart = TIME();
$relcount = 0;
$ri = new ReleaseImage();
$nzb = new NZB();
$consoletools = new ConsoleTools();

$pdo->queryExec("UPDATE groups SET first_record = 0, first_record_postdate = NULL, last_record = 0, last_record_postdate = NULL, last_updated = NULL");
echo $c->primary("Reseting all groups completed.");

$arr = array("tvrage", "releasenfo", "releasecomment", 'sharing', 'sharing_sites', "usercart", "usermovies", "userseries", "movieinfo", "musicinfo", "releasefiles", "releaseaudio", "releasesubs", "releasevideo", "releaseextrafull", "parts", "partrepair", "binaries", "collections", "releases");
foreach ($arr as &$value) {
	$rel = $pdo->queryExec("TRUNCATE TABLE $value");
	if ($rel !== false)
		echo $c->primary("Truncating ${value} completed.");
}
unset($value);

if ($pdo->dbSystem() === 'mysql') {
	$sql = "SHOW table status";
} else {
	$sql = "SELECT relname FROM pg_class WHERE relname !~ '^(pg_|sql_)' AND relkind = 'r'";
}
$tables = $pdo->query($sql);
foreach ($tables as $row) {
	if ($pdo->dbSystem() === 'mysql')
		$tbl = $row['name'];
	else
		$tbl = $row['relname'];
	if (preg_match('/collections_\d+/', $tbl) || preg_match('/binaries_\d+/', $tbl) || preg_match('/parts_\d+/', $tbl) || preg_match('/partrepair_\d+/', $tbl) || preg_match('/\d+_collections/', $tbl) || preg_match('/\d+_binaries/', $tbl) || preg_match('/\d+_parts/', $tbl) || preg_match('/\d+_partrepair_\d+/', $tbl)) {
		$rel = $pdo->queryDirect(sprintf('DROP TABLE %s', $tbl));
		if ($rel !== false)
			echo $c->primary("Dropping ${tbl} completed.");
	}
}

$pdo->optimise(false, 'full');

echo $c->header("Deleting nzbfiles subfolders.");
try {
	$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pdo->getSetting('nzbpath'), RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($files as $file) {
		if (basename($file) != '.gitignore' && basename($file) != 'tmpunrar') {
			$todo = ($file->isDir() ? 'rmdir' : 'unlink');
			@$todo($file);
		}
	}
} catch (UnexpectedValueException $e) {
	echo $c->error($e->getMessage());
}

echo $c->header("Deleting all images, previews and samples that still remain.");
try {
	$dirItr = new RecursiveDirectoryIterator(nZEDb_COVERS);
	$itr = new RecursiveIteratorIterator($dirItr, RecursiveIteratorIterator::LEAVES_ONLY);
	foreach ($itr as $filePath) {
		if (basename($filePath) != '.gitignore' && basename($filePath) != 'no-cover.jpg' && basename($filePath) != 'no-backdrop.jpg') {
			@unlink($filePath);
		}
	}
} catch (UnexpectedValueException $e) {
	echo $c->error($e->getMessage());
}

echo $c->header("Getting Updated List of TV Shows from TVRage.");
$tvshows = @simplexml_load_file('http://services.tvrage.com/feeds/show_list.php');
if ($tvshows !== false) {
	foreach ($tvshows->show as $rage) {
		if (isset($rage->id) && isset($rage->name) && !empty($rage->id) && !empty($rage->name))
			$pdo->queryInsert(sprintf('INSERT INTO tvrage (rageid, releasetitle, country) VALUES (%s, %s, %s)', $pdo->escapeString($rage->id), $pdo->escapeString($rage->name), $pdo->escapeString($rage->country)));
	}
} else {
	echo $c->error("TVRage site has a hard limit of 400 concurrent api requests. At the moment, they have reached that limit. Please wait before retrying again.");
}

echo $c->header("Deleted all releases, images, previews and samples. This script ran for " . $consoletools->convertTime(TIME() - $timestart));
