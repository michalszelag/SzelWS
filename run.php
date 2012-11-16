#!/usr/bin/php
<?

///TODO 4) the documentation
///TODO 5) make instruction how to compile php to handle 60k connections.
///TODO 6) A better debuger
///TODO 7) Think how to connect multiple WS servers
///TODO 8) Make a clients and try to get this server down and think about socket blocking (slow writing slow reading).


require("classes/socketServer.php");
require("classes/webSocket.php");

$callback=new webSocket;
//$server=new socketServer("tcp://0.0.0.0:3122","server.pem",10,$callback);
$server=new socketServer("tcp://0.0.0.0:3122",false,10,$callback);

function debug($msg)
{
   echo str_replace("\r","^M",$msg)."-----\n";;
}

function doOtherThings($server,$callback)
{
   global $stdin;
   //echo ".";
   if(!$stdin)
   {
      $stdin = fopen('php://stdin', 'r');
      stream_set_blocking($stdin,0);
   }
   if(!$stdin) return;

   $x=fread($stdin,8192);
   if($x)
   {
      $callback->sendAll($server,trim($x));
      //echo "STDIN message: ".str_replace("\n","^M",$x)."\n-----\n";
   }else $callback->sendPings($server);
}


//openssl genrsa -out server.key -des3 1024
//openssl req -new -key server.key -out server.csr
//cp server.key server.key.org
//openssl rsa -in server.key.org -out server.key
//openssl x509 -req -days 365 -in server.csr -signkey server.key -out server.crt
//cat server.crt server.key>server.pem
