<?php

use Friendica\App;
use Friendica\Core\Config;

require_once("boot.php");
require_once("include/threads.php");

function shadowupdate_run(&$argv, &$argc){
	global $a;

	if (empty($a)) {
		$a = new App(dirname(__DIR__));
	}

	@include(".htconfig.php");
	require_once("include/dba.php");
	dba::connect($db_host, $db_user, $db_pass, $db_data);
	unset($db_host, $db_user, $db_pass, $db_data);

	Config::load();

	update_shadow_copy();
}

if (array_search(__file__,get_included_files())===0){
	shadowupdate_run($_SERVER["argv"],$_SERVER["argc"]);
	killme();
}
