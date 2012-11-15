#!/usr/bin/php
<?

require("classes/server.php");
require("classes/webSocket.php");

$callback=new webSocket;
//$server=new socketSserver("tcp://0.0.0.0:3122","server.pem",10,$callback);
$server=new socketSserver("tcp://0.0.0.0:3122",false,10,$callback);

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
   }
}


//openssl genrsa -out server.key -des3 1024
//openssl req -new -key server.key -out server.csr
//cp server.key server.key.org
//openssl rsa -in server.key.org -out server.key
//openssl x509 -req -days 365 -in server.csr -signkey server.key -out server.crt
//cat server.crt server.key>server.pem
