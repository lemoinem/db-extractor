<?php

require 'openid.php';

try {
    # Change 'localhost' to your domain name.
    $openid = new LightOpenID('hosting.wemakecustom.com');
    if(!$openid->mode) {
      $openid->identity = 'https://www.google.com/accounts/o8/id';
      header('Location: ' . $openid->authUrl());
    } elseif($openid->mode == 'cancel') {
      die('User has canceled authentication!');
    }
} catch(ErrorException $e) {
    echo $e->getMessage();
}

$id = array();

if(!preg_match('^([^@]+)@wemakecustom\.com$', $openid->identity, $id))
  die('Sorry, only account from wemakecustom.com are allowed to login');

$id = $id[1];

if(!isset($_POST['db'])) : ?>
<html>
  <head>
     <title>WMC DB Extractor</title>
     <script type="text/javascript" src="//ajax.googleapis.com/ajax/libs/jquery/1.9.1/jquery.min.js">
     <script type="text/javascript">
       function onWPChange() {
        $("#wp_subform").toggle($("#wordpress").checked);
       }
     </script>
  </head>
  <body>
    <form>
      <label for="db">Database: </label><input type="text" name="db" id="db"><br>
      <input type="checkbox" name="wordpress" id="wordpress" onChange="onWPChange()"><label for="wordpress">Database wordpress to update</label><br>
      <div id="wp_subform" style="display: none">
         <label for="src_domain">Source domain: </label><input type="text" name="src_domain" id="src_domain"><br>
         <label for="dst_domain">Destination domain: </label><input type="text" name="dst_domain" id="dst_domain"><br>
      </div> 
      <input type="checkbox" name="gzip" id="gzip"><label for="gzip">GZIP</label><br>
      <input type="submit">
    </form>
  </body>
</html>
<?php else: ?>
  $forbidden_chars = '\'";$';
  if(false !== strpbrk($_POST['db'], $forbidden_chars)
     || false !== strpbrk($_POST['src_domain'], $forbidden_chars)
     || false !== strpbrk($_POST['dst_domain'], $forbidden_chars))
    die('Invalid DB name');
  <?php
header('Content-Type: application/force-download');
header('Content-Disposition: attachment; filename="'.$_POST['db'].'.'.date('Y-m-d_H-i').'.sql'.($_POST['gzip'] ? '.gz' : '').'"');
$handle = popen('mysqldump --defaults-extra-file=/etc/apache2/.my.cnf --skip-lock-tables --routines --events --triggers \''. $_POST['db']  .'\'', 'r');
$cleanup = array( function() use ($handle) { pclose($handle); });
if($_POST['wordpress']) {
  $pipes = array();
  $process = proc_open(__DIR__.'/wordpress-change-url.php \''.$_POST['src_domain'].'\', \''.$_POST['dst_domain'].'\'',
                       array(array('pipe', 'r'), $handle),
                       $pipes);
  $handle = $pipes[0];
  $cleanup[] = function () use ($handle) { fclose($handle); };
  $cleanup[] = function () use ($process) { pclose($process); };
}
if($_POST['gzip']) {
  $pipes = array();
  $process = proc_open('gzip',
                       array(array('pipe', 'r'), $handle),
                       $pipes);
  $handle = $pipes[0];
  $cleanup[] = function () use ($handle) { fclose($handle); };
  $cleanup[] = function () use ($process) { pclose($process); };
}
fpassthru($handle);
foreach($cleanup as $taks) $task();
?>
<?php endif; ?>
