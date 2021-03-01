<?php


namespace app\api\service;

/**
 * curl 执行委托方法是curlRes 需要一个参数
 * Class CurlServer
 * @package app\api\service
 */
class CurlServer
{
    public $url=null;
    public $data=null;
    public $delegate=null;
    private $httpCode=200;
    private static $curl=null;
    private $head=[
        "Content-type: application/json;charset='utf-8'"
    ];
    static public function init(&$url,$delegate=null){
        if(self::$curl===null){
            self::$curl=new self();
        }
        self::$curl->url=&$url;
        self::$curl->delegate=$delegate;
        return self::$curl;
    }
    private $output=null;
    private function __construct(){}
    public function setHead($head){
        $this->head=$head;
    }
    public function curl(&$data=null){
        $this->data=&$data;
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL,$this->url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $this->head);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        if (!empty($this->data)) {
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $this->data);
        }
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        $this->output = curl_exec($curl);
        $this->httpCode=curl_getinfo($curl,CURLINFO_HTTP_CODE);
        curl_close($curl);
        return $this->res();
    }
    private function res(){
        $output=$this->output;
        if(method_exists($this->delegate,'curlRes')){
            $output=$this->delegate->curlRes(self::$curl);
        }
        $this->reset();
        return $output;
    }
    private function reset(){
        self::$curl->url=null;
        self::$curl->data=null;
        self::$curl->delegate=null;
    }
    public function getRes(){
        return $this->output;
    }
    public function httpCode(){
        return $this->httpCode;
    }
}
