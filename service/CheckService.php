<?php


namespace app\api\service;


class CheckService {
    /**
     * 身份证认证
     * @param $idCard
     * @param $realname
     * @return array
     */
    static public function idCardCheck($idCard, $realname) {
        $host = "https://idcert.market.alicloudapi.com";
        $path = "/idcard";
        $method = "GET";
        $appcode = "a85a5883cf4d4fab82b61357af657954";//开通服务后 买家中心-查看AppCode
        $headers = array();
        array_push($headers, "Authorization:APPCODE " . $appcode);
        $querys = "idCard=$idCard&name=" . urlencode($realname);

        $url = $host . $path . "?" . $querys;

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_FAILONERROR, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, true);

        if (1 == strpos("$" . $host, "https://")) {
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        }
        $out_put = curl_exec($curl);

        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        list($header, $body) = explode("\r\n\r\n", $out_put, 2);
        if ($httpCode == 200) {
            $data = json_decode($body, true);
            if ($data['status'] == "01") {
                return ['status' => 0, 'msg' => 'success', 'data' => $data];
            } else {
                return ['status' => 2, "msg" => "实名验证未通过"];
            }
        } else {
            if ($httpCode == 403 && strpos($header, "Quota Exhausted") !== false) {
                return ['status' => 2, "msg" => "套餐包次数用完"];
            } else {
                return ['status' => 2, "msg" => "参数名错误 或 其他错误"];
            }
        }
    }
}
