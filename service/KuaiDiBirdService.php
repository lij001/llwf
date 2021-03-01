<?php
namespace app\api\service;

use think\Db;


class KuaiDiBirdService
{
    public $EBusinessID;
    Public $AppKey;
    //Public $ReqURL='http://sandboxapi.kdniao.com:8080/kdniaosandbox/gateway/exterfaceInvoke.json';
    Public $ReqURL='http://api.kdniao.com/Ebusiness/EbusinessOrderHandle.aspx';
    
    public function __construct($EBusinessID,$AppKey){
        $this->EBusinessID = $EBusinessID;
        $this->AppKey = $AppKey;
    }
    
    public function getOrderTracesByJson($userdata){
        //$requestData= "{'OrderCode':'','ShipperCode':'YTO','LogisticCode':'12345678'}";
        
        $datas = array(
            'EBusinessID' => $this->EBusinessID,
            'RequestType' => '1002',
            'RequestData' => urlencode($userdata) ,
            'DataType' => '2',
        );
        $datas['DataSign'] = self::encrypt($userdata, $this->AppKey);
        $result=self::sendPost($this->ReqURL, $datas);
        
        if(!empty($result)){
            $result_tmpe = json_decode($result,true);
            if($result_tmpe['Success']){
                return [0,$result_tmpe['Traces']];
            }
            return [1,$result_tmpe['Reason']];
        }
        return [1,"查询失败"];
    }
    
    /**
     *  post提交数据
     * @param  string $url 请求Url
     * @param  array $datas 提交的数据
     * @return url响应返回的html
     */
    static function sendPost($url, $datas) {
        $temps = array();
        foreach ($datas as $key => $value) {
            $temps[] = sprintf('%s=%s', $key, $value);
        }
        $post_data = implode('&', $temps);
        $url_info = parse_url($url);
        if(empty($url_info['port']))
        {
            $url_info['port']=80;
        }
        $httpheader = "POST " . $url_info['path'] . " HTTP/1.0\r\n";
        $httpheader.= "Host:" . $url_info['host'] . "\r\n";
        $httpheader.= "Content-Type:application/x-www-form-urlencoded\r\n";
        $httpheader.= "Content-Length:" . strlen($post_data) . "\r\n";
        $httpheader.= "Connection:close\r\n\r\n";
        $httpheader.= $post_data;
        $fd = fsockopen($url_info['host'], $url_info['port']);
        fwrite($fd, $httpheader);
        $gets = "";
        $headerFlag = true;
        while (!feof($fd)) {
            if (($header = @fgets($fd)) && ($header == "\r\n" || $header == "\n")) {
                break;
            }
        }
        while (!feof($fd)) {
            $gets.= fread($fd, 128);
        }
        fclose($fd);
        
        return $gets;
    }
    
    /**
     * 电商Sign签名生成
     * @param data 内容
     * @param appkey Appkey
     * @return DataSign签名
     */
    static function encrypt($data, $appkey) {
        return urlencode(base64_encode(md5($data.$appkey)));
    }
}

