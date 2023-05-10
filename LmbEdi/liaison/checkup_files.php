<?php
$DIR_MODULE = realpath(dirname(realpath(__FILE__))."/../");
if(!empty($_REQUEST['get_check'])){
	function find_all_files($dir)
	{
		$root = scandir($dir);
		$result = array();
		foreach($root as $value)
		{
			if($value === '.' || $value === '..') {continue;}
			if(is_file("$dir/$value")) {$result[]="$dir/$value";continue;}
			foreach(find_all_files("$dir/$value") as $value)
			{
				$result[]=$value;
			}
		}
		return $result;
	}
	header("Content-type: text/xml");
	$dom = new DOMDocument();
	$dom_root = $dom->createElement("files");
	$dom->appendChild($dom_root);
	
	$files = find_all_files($DIR_MODULE);
	sort($files);
	foreach($files as $file){
		if(strpos($file, "/log/")) continue;
		if(strpos($file, "/pid/")) continue;
		
		$path = str_replace($DIR_MODULE, "", $file);
		$md5 = md5_file($file);
		
		$f = $dom->createElement("file");
		$f->setAttribute("path", $path);
		$f->setAttribute("checksum", $md5);
		$dom_root->appendChild($f);
	}
	echo $dom->saveXML();
} else if(!empty($_REQUEST['get_file']) && is_file($DIR_MODULE.$_REQUEST['get_file'])){
	$_REQUEST['get_file'] = str_replace("%20", " ", $_REQUEST['get_file']);
	echo file_get_contents($DIR_MODULE.$_REQUEST['get_file']);
}