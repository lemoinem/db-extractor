#!/usr/bin/env php
<?php
/**
 * PHP script to replace site url in Wordpress database dump, even with WPML
 * @link https://gist.github.com/2227920
 */

if (!empty($argv[1]) && $argv[1] == 'update') {
  $file = file_get_contents('https://gist.github.com/lavoiesl/2227920/raw/wordpress-change-url.php');
  $md5 = md5($file);
  $current = md5_file(__FILE__);
  if ($md5 == $current) {
    echo "Already up-to-date.\n";
  } else {
    file_put_contents(__FILE__, $file);
    echo "Updated. MD5: " . md5_file(__FILE__) . PHP_EOL;
  }

  chmod(__FILE__, 0755);
  exit(0);
}

if (empty($argv[1]) || empty($argv[2])) {
  file_put_contents('php://stderr', "Usage: " . basename(__FILE__) . " http://old-domain.com https://new-domain.com < database.orig.sql > database.new.sql\n");
  exit(1);
}

$old_url = $argv[1];
$new_url = $argv[2];
$input = isset($argv[3]) ? $argv[3] : 'php://stdin';
$old_host = parse_url($old_url, PHP_URL_HOST);

$length_diff = strlen($new_url) - strlen($old_url);

$replace_serialized = function($matches) use ($old_url, $new_url, $length_diff) {
  $return = $matches[0];
  // We do the actual quote escaping here, otherwise preg crashes when string is too long
  if (preg_match('#s:(\d+):"((?:[^"\\\\]|\\\\.)*)'.$old_url.'#', $return, $m)) {
    $new_length = $m[1] + $length_diff;
    $return = "s:$new_length:\"{$m[2]}{$new_url}";
  }
  return $return;
};

$fp = fopen($input, "r") or die("can't read $input");
$ferr = fopen('php://stderr', 'w');

$n = 0;
$replaces = 0;
$errors = 0;
while (!feof($fp)) {
  $line = fgets($fp);

  // Serialized string
  $line = preg_replace_callback('#s:\d+:".*'.$old_url.'#', $replace_serialized, $line, -1, $count);
  $replaces += $count;

  // HTML links, encoded or not
  $line = preg_replace("# (href|src)=(\"|&quot;)$old_url#", " $1=$2{$new_url}", $line, -1, $count);
  $replaces += $count;

  // Simple SQL value
  $line = preg_replace("#,\s*'$old_url#", ", '$new_url", $line, -1, $count);
  $replaces += $count;
  echo $line;

  // Output lines that still mention old-domain.com so you know if it missed a couple
  if ($ferr && ($pos = strpos($line, $old_host)) !== false) {
    $errors++;
    $excerpt = 30;
    $length = strlen($line);

    $min = max($pos - $excerpt, 0);
    $max = min($pos + strlen($old_host) + $excerpt, $length);

    fwrite($ferr, "$n: ");

    if ($min > 0) fwrite($ferr, '...');
    fwrite($ferr, substr($line, $min, $max - $min));
    if ($max < $length) fwrite($ferr, '...');

    fwrite($ferr, "\n");
  }

  $n++;
}
fclose($fp);

fwrite($ferr, "Lines: $n\n");
fwrite($ferr, "Replaced: $replaces\n");
fwrite($ferr, "Still existing: $errors\n");

fclose($ferr);
