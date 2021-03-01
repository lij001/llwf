<?php


namespace app\api\service\Xyb2b;


use think\Exception;

class BaseClient extends Request {
    /**
     * 获取签名,数组排序
     * @param int[] $param
     * @return int[]
     */
    public function getSign($param) {
        $sign_type = null;
        $sku_list = null;
        if (isset($param['sign'])) unset($param['sign']);
        if (isset($param['sign_type'])) {
            $sign_type = $param['sign_type'];
            unset($param['sign_type']);
        }
        if (isset($param['sku_list'])) {
            $sku_list = $param['sku_list'];
            ksort($sku_list);
            $this->toJson($param['sku_list']);
        }
        ksort($param);
        $arr = [];
        foreach ($param as $k => $v) {
            $arr[] = $k . '=' . $v;
        }
        $paramStr = implode('&', $arr) . self::XY_SECRET_KEY;
        $sign = md5($paramStr);
        $param['sign'] = $sign;
        $param['sign_type'] = $sign_type ?: 'MD5';
        if (!empty($sku_list)) $param['sku_list'] = $sku_list;
        ksort($param);
        return $param;
    }

    /**
     * 数组转伪Json字符串方便sign
     * @param $arr
     */
    public function toJson(&$arr) {
        $temp = [];
        foreach ($arr as $v) {
            $temp2 = [];
            ksort($v);
            foreach ($v as $kk => $vv) {
                $temp2[] = $kk . ':' . $vv;
            }
            $temp2str = implode(',', $temp2);
            $temp[] = '{' . $temp2str . '}';
        }
        $tempstr = '[' . implode(',', $temp) . ']';
        $arr = $tempstr;
    }

    /**
     * curl请求
     * @param $url
     * @param $data
     * @return bool|string
     */
    public function curlPost($url, $data) {
        $headers = [
            "Content-type: application/json;charset='utf-8'",
            "Accept: application/json",
            "Cache-Control: no-cache",
            "Pragma: no-cache"
        ];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); //设置超时
        if (0 === strpos(strtolower($url), 'https')) {
            　　curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0); //对认证证书来源的检查
            　　curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0); //从证书中检查SSL加密算法是否存在
        }
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $rtn = curl_exec($ch);//CURLOPT_RETURNTRANSFER 不设置  curl_exec返回TRUE 设置  curl_exec返回json(此处) 失败都返回FALSE
        curl_close($ch);
        return $this->response($rtn);
    }

    private function response($response) {
        if (empty($response)) throw new \Exception('返回信息为空!');
        $response = json_decode($response, 1);
        if ($response === false) throw new \Exception('返回数据不是json格式!');
        if ($response['ret_code'] != 200) throw new \Exception('结果错误!' . '“' . $response['ret_msg'] . "”");
        return $response;
    }
}
