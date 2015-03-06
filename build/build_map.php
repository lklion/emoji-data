<?php
	error_reporting((E_ALL | E_STRICT) ^ E_NOTICE);

	$dir = dirname(__FILE__);

	include('catalog.php');
	include('catalog_names.php');
	include('catalog_vars.php');


	#
	# load text mappings
	#

	$lines = file('catalog_text_toascii.txt');
	$text = array();
	foreach ($lines as $line){
		$line = trim($line);
		if (strlen($line)){
			$bits = preg_split('!\s+!', $line, 2);
			$text[$bits[0]] = $bits[1];
		}
	}


	#
	# load extra apple data
	#

	$json = file_get_contents('apple_extra.json');
	$apple_data = json_decode($json, true);


	#
	# build the simple ones first
	#

	$out = array();
	$out_unis = array();

	foreach ($catalog as $row){

		$img_key = StrToLower(encode_points($row['unicode']));
		$shorts = $catalog_names[$img_key];
		$name = $row['char_name']['title'];

		if (preg_match("!^REGIONAL INDICATOR SYMBOL LETTERS !", $name)){
			array_unshift($shorts, 'flag-'.$shorts[0]);
		}

		$out[] = simple_row($img_key, $shorts, array(
			'name'		=> $name,
			'unified'	=> encode_points($row['unicode']),
			'docomo'	=> encode_points($row['docomo'  ]['unicode']),
			'au'		=> encode_points($row['au'      ]['unicode']),
			'softbank'	=> encode_points($row['softbank']['unicode']),
			'google'	=> encode_points($row['google'  ]['unicode']),
		));

		$out_unis[$img_key] = 1;
	}


	#
	# were there any codepoints we have an image for, but no data for?
	#

	echo "Finding extra emoji from UCD: ";

	foreach ($catalog_names as $uid => $names){
		if ($uid == '_') continue;

		if (!$out_unis[$uid]){
			echo  '.';
			$out[] = build_character_data($uid, $names);
		}
	}
	echo " DONE\n";


	#
	# extra non-standard CPs
	#

	echo "Adding extra Apple emoji: ";

	foreach ($apple_data['emoji'] as $cps => $arr){

		$img_key = StrToLower($cps);
		$img_key = str_replace('200d-', '', $img_key);

		$short_names = array($arr[0]);
		$name = StrToUpper($arr[1]);

		if (substr($arr[0], 0, 5) == 'flag-'){
			$short_names[] = substr($arr[0], 5);
			$name = "REGIONAL INDICATOR SYMBOL LETTERS ".StrToUpper(substr($arr[0], 5));
		}

		echo  '.';
		$out[] = simple_row($img_key, $short_names, array(
			'name'		=> $name,
			'unified'	=> $cps,
		));
	}

	echo "OK\n";


	function build_character_data($img_key, $short_names){

		global $text;

		$uni = StrToUpper($img_key);

		$line = shell_exec("grep -e ^{$uni}\\; UnicodeData.txt");
		list($junk, $name) = explode(';', $line);


		return simple_row($img_key, $short_names, array(
			'name'		=> $name,
			'unified'	=> $uni,
		));
	}


	function simple_row($img_key, $shorts, $props){

		$vars = $GLOBALS['catalog_vars'][$img_key];
		if (!is_array($vars)) $vars = array();
		foreach ($vars as $k => $v) $vars[$k] = StrToUpper($v);	

		if (!is_array($shorts)) $shorts = array();
		$short = count($shorts) ? $shorts[0] : null;

		$ret = array(
			'name'		=> null,
			'unified'	=> null,
			'variations'	=> $vars,
			'docomo'	=> null,
			'au'		=> null,
			'softbank'	=> null,
			'google'	=> null,
			'image'		=> $img_key.'.png',
			'sheet_x'	=> 0,
			'sheet_y'	=> 0,
			'short_name'	=> $short,
			'short_names'	=> $shorts,
			'text'		=> $GLOBALS['text'][$short],
			'apple_img'	=> null,
			'hangouts_img'	=> null,
			'twitter_img'	=> null,
			'emojione_img'	=> null,
		);

		$ret['apple_img_path']		= find_image($ret['image'], "gemoji/images/emoji/unicode/");
		$ret['hangouts_img_path']	= find_image($ret['image'], "img-hangouts-64/");
		$ret['twitter_img_path']	= find_image($ret['image'], "img-twitter-72/");
		$ret['emojione_img_path']	= find_image($ret['image'], "emojione/assets/png/");

		$ret['apple_img']		= !is_null($ret['apple_img_path']);
		$ret['hangouts_img']		= !is_null($ret['hangouts_img_path']);
		$ret['twitter_img']		= !is_null($ret['twitter_img_path']);
		$ret['emojione_img']		= !is_null($ret['emojione_img_path']);

		foreach ($props as $k => $v) $ret[$k] = $v;

		return $ret;
	}

	function find_image($image, $img_path){

		$root = "{$GLOBALS['dir']}/../";

		$src = "{$img_path}{$image}";
		if (file_exists($root.$src)) return $src;

		list($a, $b) = explode('.', $image);
		$upper_name = StrToUpper($a).'.'.$b;
		$src = "{$img_path}{$upper_name}";
		if (file_exists($root.$src)) return $src;

		return null;
	}


	#
	# sort everything into a nice order
	#

	foreach ($out as $k => $v){
		$out[$k]['sort'] = str_pad($v['unified'], 20, '0', STR_PAD_LEFT);
	}

	usort($out, 'sort_rows');

	foreach ($out as $k => $v){
		unset($out[$k]['sort']);
	}

	function sort_rows($a, $b){
		return strcmp($a['sort'], $b['sort']);
	}


	#
	# assign positions
	#

	$y = 0;
	$x = 0;
	$num = ceil(sqrt(count($out)));

	foreach ($out as $k => $v){
		$out[$k]['sheet_x'] = $x;
		$out[$k]['sheet_y'] = $y;
		$y++;
		if ($y == $num){
			$x++;
			$y = 0;
		}
	}


	#
	# write map
	#

	echo "Writing map: ";

	$fh = fopen('../emoji.json', 'w');
	fwrite($fh, json_encode($out));
	fclose($fh);

	echo "DONE\n";


	function encode_points($points){
		$bits = array();
		if (is_array($points)){
			foreach ($points as $p){
				$bits[] = sprintf('%04X', $p);
			}
		}
		if (!count($bits)) return null;
		return implode('-', $bits);
	}
