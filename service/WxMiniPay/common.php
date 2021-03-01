<?php

namespace app\api\service\WxMiniPay;


class common {
    const APPID = 'wx20edb06e9a7d5a21';
    const MCH_ID = 1555598621;
    const KEY = 'uhj0q4j60o31tgfo2aww2d4883f1v1d8';

    /**
     * 产生一个指定长度的随机字符串,并返回给用户
     * @param  $len //产生字符串的长度
     * @return string 随机字符串
     */
    public function GenRandomString($len = 32) {
        $chars = array(
            "a", "b", "c", "d", "e", "f", "g", "h", "i", "j", "k",
            "l", "m", "n", "o", "p", "q", "r", "s", "t", "u", "v",
            "w", "x", "y", "z", "A", "B", "C", "D", "E", "F", "G",
            "H", "I", "J", "K", "L", "M", "N", "O", "P", "Q", "R",
            "S", "T", "U", "V", "W", "X", "Y", "Z", "0", "1", "2",
            "3", "4", "5", "6", "7", "8", "9"
        );
        $charsLen = count($chars) - 1;
        // 将数组打乱
        shuffle($chars);
        $output = "";
        for ($i = 0; $i < $len; $i++) {
            $output .= $chars[mt_rand(0, $charsLen)];
        }
        return $output;
    }

    /**
     * 签名 $data要先排好顺序
     * @param $data
     * @return string
     */
    public function Sign($data) {
        ksort($data);
        $stringA = '';
        foreach ($data as $key => $value) {
            if (!$value) continue;
            if ($stringA) $stringA .= '&' . $key . "=" . $value;
            else $stringA = $key . "=" . $value;
        }
        $wx_key = $this::KEY;//申请支付后有给予一个商户账号和密码，登陆后自己设置的key
        $stringSignTemp = $stringA . '&key=' . $wx_key;
        return strtoupper(md5($stringSignTemp));
    }

    /**
     * 将xml转为array
     * @param string $xml
     * return array
     * @return bool|mixed
     */
    public function XmlToData($xml) {
        if (!$xml) {
            return false;
        }
        //将XML转为array
        //禁止引用外部xml实体
        libxml_disable_entity_loader(true);
        $data = json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        return $data;
    }

    /**
     * 输出xml字符
     * @param $params //参数名称
     * return string 返回组装的xml
     *
     * @return bool|string
     */
    public function DataToXml($params) {
        if (!is_array($params) || count($params) <= 0) {
            return false;
        }
        $xml = "<xml>";
        foreach ($params as $key => $val) {
            if (is_numeric($val)) {
                $xml .= "<" . $key . ">" . $val . "</" . $key . ">";
            } else {
                $xml .= "<" . $key . "><![CDATA[" . $val . "]]></" . $key . ">";
            }
        }
        $xml .= "</xml>";
        return $xml;
    }

    /**
     * 以post方式提交xml到对应的接口url
     *
     * @param string $xml 需要post的xml数据
     * @param string $url url
     * @param bool $useCert 是否需要证书，默认不需要
     * @param int $second url执行超时时间，默认30s
     * @return bool|string
     * @throws
     */
    public function PostXmlCurl($xml, $url, $useCert = false, $second = 30) {
        $ch = curl_init();
        //设置超时
        curl_setopt($ch, CURLOPT_TIMEOUT, $second);
        curl_setopt($ch, CURLOPT_URL, $url);
        //
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $useCert);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $useCert ? 2 : 0);
        //设置header
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        //要求结果为字符串且输出到屏幕上
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        if ($useCert == true) {
            //TODO 以下两种方式需选择一种
            /*------- --第一种方法，cert 与 key 分别属于两个.pem文件--------------------------------*/
            //使用证书：cert 与 key 分别属于两个.pem文件
            //默认格式为PEM，可以注释
            curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'PEM');
            curl_setopt($ch, CURLOPT_SSLCERT, '绝对路径');
            //默认格式为PEM，可以注释
            curl_setopt($ch, CURLOPT_SSLKEYTYPE, 'PEM');
            curl_setopt($ch, CURLOPT_SSLKEY, '绝对路径');
            /**
             * 补充 当找不到ca根证书的时候还需要rootca.pem文件
             * TODO 注意，微信给出的压缩包中，有提示信息：
             *      由于绝大部分操作系统已内置了微信支付服务器证书的根CA证书,
             *      2018年3月6日后, 不再提供CA证书文件（rootca.pem）下载
             */
            //curl_setopt($ch, CURLOPT_CAINFO,self::APICLIENT_CA);

            /*----------第二种方式，两个文件合成一个.pem文件----------------------------------------*/
            //curl_setopt($ch,CURLOPT_SSLCERT,getcwd().'/all.pem');

        }
        //post提交方式
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        //运行curl
        $data = curl_exec($ch);
        //返回结果
        if ($data) {
            curl_close($ch);
            return $data;
        } else {
            $error = curl_errno($ch);
            curl_close($ch);
            return false;
        }
    }
}
