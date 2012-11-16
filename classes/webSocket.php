<?php

class webSocket implements serverCallbacks
{
   const ft_continuation = 0x00;
   const ft_text = 0x01;
   const ft_bin = 0x02;

   const ft_close = 0x08;

   const ft_ping = 0x09;
   const ft_pong = 0x0a;
   const ping_secs=10; /// set to 10 for tests normal set it to 30-60 secs or more
   const ping_wait=3; /// how long to wait for a pong after ping

   private $clients=array();
   public function onConnect(socketServer $ser,$cliid)
   {
      $this->clients[$cliid]=array(
         "id"=>$cliid,
         "unparsed"=>"",
         "message_comming"=>"",
         "headers"=>"",
         "handshake"=>false,
         "sec-websocket-key"=>false,
         "last_contact"=>time(),
         "ping_sent"=>false,
      );
      debug("Client connected: ".$cliid);
         //$cliid;
      //foreach($ser->getClients() as $cli) if($cli!=$cliid) $ser->write($cli,"New client:".$cliid."\n");
   }

   public function sendPings(socketServer $ser)
   {
      $ping_frame=chr(0x89).chr(0x00);
      $actual_time=time();
      foreach($this->clients as $cliid=>&$client) if($client["last_contact"]+self::ping_secs<$actual_time&&!($client["ping_sent"]))
      {
         $client["ping_sent"]=time();
         $ser->write($cliid,$ping_frame);
         debug("PING SENT[".$cliid."]");
         //print_r($this->clients[$cliid]);
      }elseif($client["last_contact"]+self::ping_secs<$actual_time&&$client["ping_sent"]+self::ping_wait<$actual_time)
      {
         $ser->clientDisconnect($cliid);
         unset($this->clients[$cliid]);
      }
   }

   public function sendAll($ser,$message)
   {
      debug("SENDING MESSAGE: ".$message);
      //echo $this->make_frame($message)."\n\n";
      foreach($this->clients as $cliid=>$clidata) $ser->write($cliid,$this->make_frame($message));
   }

   public function onDisconnect(socketServer $ser,$cliid)
   {
      debug("Client DISconnected: ".$cliid);
      unset($this->clients[$cliid]);
      //foreach($ser->getClients() as $cli) if($cli!=$cliid) $ser->write($cli,"Client disconnected:".$cliid."\n");
   }

   public function onMessage(socketServer $ser,$cliid,$message)
   {
      if(!($this->clients[$cliid]["handshake"]))
      {
         $this->clients[$cliid]["unparsed"].=$message;
         if(strpos($this->clients[$cliid]["unparsed"],"\r\n\r\n"))
         {
            debug("HEADERS[".$cliid."]:\n".$this->clients[$cliid]["unparsed"]);
            $xdata=explode("\r\n\r\n",$this->clients[$cliid]["unparsed"],2);
            $this->clients[$cliid]["unparsed"]=$xdata[1];
            $headers=$this->parseHeaders($xdata[0]);
            if($headers["sec-websocket-key"]&&(int)$headers["sec-websocket-version"]>=13)
            {
               $res_key=base64_encode(sha1($headers["sec-websocket-key"]."258EAFA5-E914-47DA-95CA-C5AB0DC85B11",true));
               $res="HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: ".$res_key."\r\n\r\n";
               debug("RES:\n".$res);
               $ser->write($cliid,$res);
               $this->clients[$cliid]["unparsed"]="";
               $this->clients[$cliid]["handshake"]=true;
               $this->clients[$cliid]["sec-websocket-key"]=$headers["sec-websocket-key"];
               //print_r($this->clients[$cliid]);

               //echo "Great staff accepting this ws\n";
            }else
            {
               debug("[".$cliid."]bad ws closing...");
               $ser->clientDisconnect($cliid);
               unset($this->clients[$cliid]);
            }
         }
      }else
      {

         debug("SOCKET MESSAGE[".$cliid."]:".strlen($message));
         //for($i=0,$n=strlen($message);$i<$n;$i++) echo sprintf("%x",ord($message[$i])).":";echo "\n";
         $this->clients[$cliid]["unparsed"].=$message;

         $parsed=$this->parse_frame($this->clients[$cliid]["unparsed"]);
         if($parsed===false) ; ///TODO close the socket by the third time it happends.
         else
         {
            $this->clients[$cliid]["unparsed"]=$parsed["nextFrame"];
            $this->clients[$cliid]["message_comming"].=$parsed["decoded"];
            if($parsed["fin"]&&$parsed["opcode"]==self::ft_pong)
            {
               $this->clients[$cliid]["ping_sent"]=false;
               $this->clients[$cliid]["message_comming"]="";

            }elseif($parsed["fin"])
            {
               $this->onWSmessage($ser,$cliid,$this->clients[$cliid]["message_comming"]);
               $this->clients[$cliid]["message_comming"]="";
            }
            $this->clients[$cliid]["last_contact"]=time();
            ///TODO there can be another message that is in the next frame and unparsed we should make this in while, but with a good error support from parse_frame
         }
      }
   }

   private function onWSmessage(socketServer $ser,$cliid,$message)
   {
      debug("MESSAGE[".$cliid."]:".$message);
   }

   public function parse_frame($data)
   {
      if(strlen($data)<2) return false;

      $firstByte=ord($data[0]);
      $secondByte=ord($data[1]);
      $fin=$firstByte&0x80;
      $opcode=$firstByte&0x0F;
      $mask=$secondByte&0x80;
      $pay7=$secondByte&0x7F;

      $raw=substr($data,2);
      if(strlen($raw)<$pay7) return false;

      if($pay7==126)
      {
         $len=array_pop(unpack("n",substr($raw,0,2)));
         $raw=substr($raw,2);
      }elseif($pay7==127)
      {
         $l1=array_pop(unpack("N",substr($raw,0,2)));
         $l2=array_pop(unpack("N",substr($raw,2,4)));
         $len=$l1*0x0100000000+$l2;
         $raw=substr($raw,4);
         ///TODO php 64 bit integer is SIGNED, so think here about this!!!
      }else $len=$pay7;

      $maskValue="";

      if($mask&&strlen($raw)<4) return false;
      elseif($mask)
      {
         $maskValue=substr($raw,0,4);
         $raw=substr($raw,4);
      }

      if(strlen($raw)<$len) return false;
      if(strlen($raw)>$len)
      {
         $to_decode=substr($raw,0,$len);
         $nextFrame=substr($raw,$len);
      } else
      {
         $to_decode=$raw;
         $nextFrame="";
      }

      if($mask)
      {
         $decoded="";
         for($i=0,$n=strlen($to_decode);$i<$n;$i++)
         {
            $mask_pos=$i%4;
            $decoded.=chr(ord($to_decode[$i])^ord($maskValue[$mask_pos]));
         }
      }else $decoded=$to_decode;

      return array(
         "opcode"=>$opcode,
         "fin"=>$fin,
         "decoded"=>$decoded,
         "len"=>$len,
         "nextFrame"=>$nextFrame
      );
   }

   public function parseHeaders($data)
   {
      $head=explode("\r\n",trim($data));
      $head2=array();
      for($i=0,$n=sizeof($head);$i<$n;$i++) if($i==0)
      {
         //$ht=explode(" ",$head[$i],3);
         $head2["HTTP"]=$head[$i];

      }else{
         $ht=explode(": ",$head[$i],2);
         $head2[strtolower($ht[0])]=$ht[1];
      }
      return $head2;
   }

   public function make_frame($data)
   {
      $len=strlen($data);
      if($len>(0x7FFFFFFFFFFFFFFF)) return false;

      $firstByte=0x80|self::ft_text;

      if($len<126) $pay7=$len;
      elseif($len<=0xFFFF) $pay7=126;
      else $pay7=127;

      $secondByte=0x00|$pay7;
      $raw=chr($firstByte).chr($secondByte);

      if($pay7==126) $raw.=pack("n",$len);
      elseif($pay7==127) $raw.=pack("N",$len>>32).pack("N",$len&0xFFFFFFFF);

      return $raw.$data;
   }
}
