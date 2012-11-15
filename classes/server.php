<?

interface serverCallbacks{
   public function onConnect(socketSserver $ser,$cliid);
   public function onDisconnect(socketSserver $ser,$cliid);
   public function onMessage(socketSserver $ser,$cliid,$message);
}


class socketSserver
{
   private $master;
   private $clients;
   private function debug($msg,$sev)
   {
      echo "DEBUG[".$sev."]:".$msg."\n";
      if($sev>=3) exit();

   }

   public function write($cliid,$message)
   {
      if($this->clients[$cliid]) return fwrite($this->clients[$cliid],$message);
      else return false;
   }

   public function getClients()
   {
      return array_keys($this->clients);
   }

   public function clientDisconnect($cliid)
   {
      if($this->clients[$cliid])
      {
         $ret=stream_socket_shutdown(stream_socket_accept($this->clients[$cliid]),2);
         unset($this->clients[$cliid]);
         //$call_back->onDisconnect($this,$cliid);
         $this->debug("Client disconnected:".$cliid,1);
         return $ret;
      }else return false;
         

   }

   private function setupSSL($cert_file)
   {
      if(!($cert_file)) return null;
      if(!file_exists($cert_file)) $this->debug("No cert file",3);
      $context = stream_context_create();
      stream_context_set_option($context, 'ssl', 'local_cert', $cert_file);
      stream_context_set_option($context, 'ssl', 'allow_self_signed', true);
      stream_context_set_option($context, 'ssl', 'verify_peer', false);
      return $context;
   }


   public function __construct($listen,$ssl,$max_clients,serverCallbacks $call_back,$buffer=8192)
   {
      $cliid=null;
      $new_conn=null;
      $i=null;
      $n=null;
      if($ssl) $this->master=stream_socket_server($listen,$errno,$errstr,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,$this->setupSSL($ssl));
      else $this->master=stream_socket_server($listen,$errno,$errstr,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
      if(!($this->master)) $this->debug("Cannot open socket",3);
      $this->clients=array();
      while(1)
      {
         $sockets=array_merge(array("admin"=>$this->master),$this->clients);
         $n=@stream_select($sockets,$_w = NULL, $_e = NULL, 0,10000);
         if($n===false) die("FATAL ERROR\n");
         for($i=0;$i<$n;++$i)
         {
            if($sockets[$i]===$this->master)
            {
               if(sizeof($this->clients)+1>$max_clients)
               {
                  $this->debug("To many open connections",1);
                  stream_socket_shutdown(stream_socket_accept($this->master),2);
               }
               else
               {
                  $new_conn=stream_socket_accept($this->master);
                  //if($ssl) stream_socket_enable_crypto($new_conn,true,STREAM_CRYPTO_METHOD_SSLv23_SERVER);//STREAM_CRYPTO_METHOD_SSLv3_SERVER
                  if($ssl) stream_socket_enable_crypto($new_conn,true,STREAM_CRYPTO_METHOD_TLS_SERVER);//STREAM_CRYPTO_METHOD_SSLv3_SERVER
                  $cliid=uniqid();
                  $this->clients[$cliid]=$new_conn;
                  
                  $call_back->onConnect($this,$cliid);
                  unset($new_conn);
                  $this->debug("New connection:".$cliid,1);
               }
            }else
            {
               $cliid=array_search($sockets[$i],$this->clients,true);
               if($cliid)
               {
                  $sock_data=fread($sockets[$i],$buffer);

                  if(strlen($sock_data)===0||$sock_data===false)
                  {
                     unset($this->clients[$cliid]);
                     stream_socket_shutdown($sockets[$i],2);
                     unset($sockets[$i]);
                     $call_back->onDisconnect($this,$cliid);
                     $this->debug("Client disconnected:".$cliid,1);
                  } else $call_back->onMessage($this,$cliid,$sock_data);
               }
            }
         }
         doOtherThings($this,$call_back);
      }
   }
}
