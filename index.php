<?php

require 'openid.php';

session_start();

if(!isset($_SESSION['authorized_as'])) {
  try {
    $openid = new LightOpenID(gethostname().'.hosting.wemakecustom.com');
    if(!$openid->mode) {
      $openid->identity = 'https://www.google.com/accounts/o8/id';
      $openid->required = array('contact/email');
      header('Location: ' . $openid->authUrl());
    } elseif($openid->mode == 'cancel') {
      die('User has canceled authentication!');
    } elseif (!$openid->validate()) {
      die('Invalid authentication!');
    }
  } catch(ErrorException $e) {
    echo $e->getMessage();
  }
  
  $id = array();
  $email = $openid->getAttributes();
  $email = $email['contact/email'];
  
  if(!preg_match('/^([^@]+)@wemakecustom\.com$/', $email, $id))
    die('Sorry, only accounts from wemakecustom.com are allowed to login (you logged in as "'.$email.'")');
  
  $id = $id[1];
  
  $_SESSION['authorized_as'] = array($id, $email);
}

if(!isset($_GET['submit'])) : ?>
<html>
  <head>
     <title>WMC DB Extractor</title>
     <style type="text/css">
       #wp_subform { display: none; }
       #wordpress:checked ~ #wp_subform { display: block; }
     </style>
  </head>
  <body>
       <?php
     $db_access = parse_ini_file('/etc/apache2/.my.cnf');
$mysql = new mysqli($db_access['host'], $db_access['user'], $db_access['password']);
$dbs = $mysql->query('SHOW DATABASES');
$dbs = $dbs->fetch_all();
        ?>
    <form method="get">
      <label for="db">Database: </label><select name="db" id="db">
  <?php foreach ($dbs as $db) {
    $dbname = $db[0];
    if(in_array($dbname, array('mysql', 'information_schema')))
      continue;
    echo '<option'.((isset($_GET['db']) && $_GET['db'] == $dbname) ? ' selected' : '').'>'.htmlspecialchars($dbname).'</option>'."\n";
  } ?>
      </select><br>
      <input type="checkbox" name="wordpress" id="wordpress"<?php if(isset($_GET['wordpress'])) echo ' checked'; ?>><label for="wordpress">Database wordpress to update</label><br>
      <div id="wp_subform">
  <label for="src_domain">Source domain: </label><input type="text" name="src_domain" id="src_domain" <?php if(isset($_GET['src_domain'])) echo ' value="'.htmlspecialchars($_GET['src_domain']).'"'; ?>><br>
         <label for="dst_domain">Destination domain: </label><input type="text" name="dst_domain" id="dst_domain" <?php if(isset($_GET['dst_domain'])) echo ' value="'.htmlspecialchars($_GET['dst_domain']).'"'; ?>><br>
      </div> 
         <input type="checkbox" name="gzip" id="gzip"<?php if(isset($_GET['gzip'])) echo ' checked'; ?>><label for="gzip">GZIP</label><br>
      <input type="submit" name="submit"><input type="submit" name="get_url" value="Generate URL">
    </form>
  </body>
</html>
<?php else: ?>
<?php
  $forbidden_chars = '\'";$';
  if(false !== strpbrk($_GET['db'], $forbidden_chars)
     || false !== strpbrk($_GET['src_domain'], $forbidden_chars)
     || false !== strpbrk($_GET['dst_domain'], $forbidden_chars))
    die('Invalid DB name');

function print_debug($message) {
  if(/**/false/*/true/**/)
    trigger_error($message);
}

function get_dump($var) {
  ob_start();
  var_dump($var);
  $dump = ob_get_contents();
  ob_end_clean();
  return $dump;
}

header('Content-Type: application/force-download');
header('Content-Disposition: attachment; filename="'.$_GET['db'].'.'.date('Y-m-d_H-i').'.sql'.(isset($_GET['gzip']) ? '.gz' : '').'"');
$cmd = 'mysqldump --defaults-extra-file=/etc/apache2/.my.cnf --net-buffer-length=4096 --skip-lock-tables --routines --events --triggers \''. $_GET['db']  .'\'';
print_debug('Command: '.$cmd);
$handle = popen($cmd, 'r');
$err_pipes = array();
$cleanup = array( function() use ($handle) { print_debug('Cleaning mysqldump'); pclose($handle); });
if(isset($_GET['wordpress'])) {
  $pipes = array();
  $cmd = __DIR__.'/wordpress-change-url.php \''.$_GET['src_domain'].'\', \''.$_GET['dst_domain'].'\'';
  trigger_error('Command: '.$cmd);
  $process = proc_open($cmd,
                       array($handle, array('pipe', 'w'), array('pipe', 'w')),
                       $pipes);
  print_debug(get_dump($pipes));
  $handle = $pipes[1];
  $err = $err_pipes[] = $pipes[2];
  $cleanup[] = function () use ($err) { print_debug('Cleaning wordpress change stderr'); fclose($err); };
  $cleanup[] = function () use ($handle) { print_debug('Cleaning wordpress change stdout'); fclose($handle); };
  $cleanup[] = function () use ($process) { print_debug('Cleaning wordpress change'); proc_close($process); };
}
if(isset($_GET['gzip'])) {
  $pipes = array();
  $cmd = 'gzip';
  trigger_error('Command: '.$cmd);
  $process = proc_open($cmd,
                       array($handle, array('pipe', 'w'), array('pipe', 'w')),
                       $pipes);
  print_debug(get_dump($pipes));
  $handle = $pipes[1];
  $err = $err_pipes[] = $pipes[2];
  $cleanup[] = function () use ($err) { print_debug('Cleaning gzip stderr'); fclose($err); };
  $cleanup[] = function () use ($handle) { print_debug('Cleaning gzip stdout'); fclose($handle); };
  $cleanup[] = function () use ($process) { print_debug('Cleaning gzip'); proc_close($process); };
}
$result = fpassthru($handle);
foreach($err_pipes as $pipe) {
  if (!is_resource($pipe)) {
      print_debug(get_dump($pipe));
      continue;
    }
    stream_set_blocking($pipe, 1);
    while(!feof($pipe))
      trigger_error(fgets($pipe));
}
foreach($cleanup as $task) $task();
print_debug('fpassthru: '.($result === false ? 'ERROR' : $result));
?>
<?php endif;
