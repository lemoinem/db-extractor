<?php

// CONFIG

// emails to allows through Google Login
$allowed = parse_ini_file(__DIR__.'/confs/allowed.ini');
$uri = parse_ini_file(__DIR__.'/confs/url.ini');
$oauth = json_decode(file_get_contents(__DIR__.'/confs/oauth.json'), true);
$supports_wordpress = is_file(__DIR__.'/wordpress-change-url.php');

// END CONFIG
require_once __DIR__.'/vendor/autoload.php';

session_start();
error_reporting(-1);
ini_set('display_errors', 'on');

if (isset($_GET['logout'])) {
   unset($_SESSION['access_token']);
}

try {
   $openid = new Google_Client();
   $openid->setClientId($oauth['web']['client_id']);
   $openid->setClientSecret($oauth['web']['client_secret']);
   $openid->setRedirectUri($uri['redirect_url']);
   $openid->setScopes('email');
   $openid->setApprovalPrompt('force');

   if (!isset($_SESSION['access_token'])) {
      if (isset($_GET['error'])) {
         throw new \Exception('An error occured during the authentication process.');
      } elseif (!isset($_GET['code'])) {
         header('Location: ' . $openid->createAuthUrl());
         die();
      } else {
         $openid->authenticate($_GET['code']);
         $_SESSION['access_token'] = $openid->getAccessToken();
      }
   } else {
      $openid->setAccessToken($_SESSION['access_token']);
   }

   if (!$openid->getAccessToken()) {
      throw new \Exception('Authentication required.');
   }

   $data = $openid->verifyIdToken()->getAttributes();
   
   $email = $data['payload']['email'];

   if(!preg_match($allowed['email_regex'], $email, $id)) {
      $openid->getAuth()->revokeToken($_SESSION['access_token']);
      throw new \Exception('Sorry, your account is not allowed to login (you logged in as "'.$email.'").<br>'."\n"
                          .'(You may need to <a target="_blank" href="https://security.google.com/settings/security/permissions">revoke access to this app</a> and/or <a target="_blank" href="https://accounts.google.com/logout">log out of your Gmail Account</a> entirely.)');
   }

} catch (\Exception $e) {
   echo $e->getMessage();
   die("\n".'<br><a href="?logout">Retry</a>');
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
            <?php if ($supports_wordpress): ?>
               <input type="checkbox" name="wordpress" id="wordpress"<?php if(isset($_GET['wordpress'])) echo ' checked'; ?>><label for="wordpress">Database wordpress to update</label><br>
               <div id="wp_subform">
                  <label for="src_domain">Source domain: </label><input type="text" name="src_domain" id="src_domain" <?php if(isset($_GET['src_domain'])) echo ' value="'.htmlspecialchars($_GET['src_domain']).'"'; ?>><br>
                  <label for="dst_domain">Destination domain: </label><input type="text" name="dst_domain" id="dst_domain" <?php if(isset($_GET['dst_domain'])) echo ' value="'.htmlspecialchars($_GET['dst_domain']).'"'; ?>><br>
               </div>
            <?php else: ?>
               <div>
                  You need to download https://gist.github.com/lavoiesl/2227920
                  to support changing the WP site URL in the exported DB.
               </div>
            <?php endif; ?>
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

   function print_debug($message)
   {
      if(/**/false/*/true/**/)
         trigger_error($message);
   }

   function get_dump($var)
   {
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
   $cleanup = array( function () use ($handle) { print_debug('Cleaning mysqldump'); pclose($handle); });
   if ($supports_wordpress && isset($_GET['wordpress'])) {
      $pipes = array();
      $cmd = __DIR__.'/wordpress-change-url.php \''.$_GET['src_domain'].'\', \''.$_GET['dst_domain'].'\'';
      print_debug('Command: '.$cmd);
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
   if (isset($_GET['gzip'])) {
      $pipes = array();
      $cmd = 'gzip';
      print_debug('Command: '.$cmd);
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
   foreach ($err_pipes as $pipe) {
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
