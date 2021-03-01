<?php

namespace app\api\service;

use app\api\service\WxMiniPay\WxMiniPayService;
use think\Db;
use cmf\phpqrcode\QRcode;

class OrderService {
    public $orderID;
    public $payAmount;
    public $subject;

    public function __construct($orderID, $payAmount, $subject) {
        $this->orderID = $orderID;
        $this->payAmount = $payAmount;
        //$this->payAmount = 0.01;//朱慧蕾
        $this->subject = $subject;
    }

    public function aliPay() {
        //获取支付宝配置参数
        $config = Db::name("config")->where("id", 1)->find();
        //支付宝支付
        $zfb_appid = trim($config['zfb_appid']);
        //开发者私钥
        $zfb_develop_private_key = trim($config['zfb_develop_private_key']);
        //支付宝公钥
        $zfb_public_key = trim($config['zfb_public_key']);

        //支付宝回调地址 site_root返回当前域名
        //$notify_url = SITE_ROOT.'/api/pay/aliNotify';
        $notify_url = url("api/pay/aliNotify", '', false, true);

        $data1 = [
            'goodsName' => $this->subject,
            'orderId' => $this->orderID,
            'payPrice' => $this->payAmount,
            'notify_url' => $notify_url,
        ];
        $data2 = [
            'alipay_appid' => $zfb_appid,
            'alipay_privateKey' => $zfb_develop_private_key,
            'alipay_publicKey' => $zfb_public_key
        ];

        //参数准备
        $public_key = trim(file_get_contents(ROOT_PATH . 'cert/llwfPublic.txt'));
        $private_key = trim(file_get_contents(ROOT_PATH . 'cert/llwfPrivate.txt'));
        $data2 = [
            'alipay_appid' => $zfb_appid,                                     //应用Id
            'alipay_publicKey' => $public_key,                                               //应用公钥
            'alipay_privateKey' => $private_key,                                            //应用私钥
            'app_cert' => ROOT_PATH . '/cert/appCertPublicKey_2019091067164034.crt',   //应用公钥证书
            'root_cert' => ROOT_PATH . '/cert/alipayRootCert.crt',                     //支付宝根证书
        ];

        $paymentService = new PaymentService($this->orderID);
        //{"code":0,"msg":"返回支付签名成功","data":{"info":{"pay_sign_str":"alipay_sdk=alipay-sdk-php-20161101&app_id=2016082201785095&biz_content=%7B%22body%22%3A%22%E6%A2%A6%E5%A6%86%E5%A5%97%E8%A3%85%E6%B0%B4%E4%BB%99%E8%8A%B1%E6%B0%B4%E4%B9%B3%E9%9D%A2%E9%9C%9C%E6%8A%A4%E8%82%A4%E5%93%81%E5%A5%97%E8%A3%85%E8%A1%A5%E6%B0%B4%E4%BF%9D%E6%B9%BF%E4%BF%AE%E6%8A%A4%E8%88%92%E7%BC%93%E5%A5%B3%E5%8C%96%E5%A6%86%E5%93%81%E6%AD%A3%E5%93%81%22%2C%22subject%22%3A+%22%E6%A2%A6%E5%A6%86%E5%A5%97%E8%A3%85%E6%B0%B4%E4%BB%99%E8%8A%B1%E6%B0%B4%E4%B9%B3%E9%9D%A2%E9%9C%9C%E6%8A%A4%E8%82%A4%E5%93%81%E5%A5%97%E8%A3%85%E8%A1%A5%E6%B0%B4%E4%BF%9D%E6%B9%BF%E4%BF%AE%E6%8A%A4%E8%88%92%E7%BC%93%E5%A5%B3%E5%8C%96%E5%A6%86%E5%93%81%E6%AD%A3%E5%93%81%22%2C%22out_trade_no%22%3A+%22a46609234af4d2b3%22%2C%22timeout_express%22%3A+%2230m%22%2C%22total_amount%22%3A+%22100.00%22%2C%22product_code%22%3A%22QUICK_MSECURITY_PAY%22%7D&charset=UTF-8&format=json&method=alipay.trade.app.pay&notify_url=localhost%2Fapi%2Fpay%2FaliNotify&sign_type=RSA2&timestamp=2018-06-22+11%3A47%3A30&version=1.0&sign=O74JEgf8ajo9LeJ6T41g%2FTcfZ5uw1VKNaxUeadRgWQylex0gQXziMCFEfdJ88HXJ%2BDbXdyDM2zp7GCziwHPUVdPfpK4ajFvg7n66w6w65PJmngm5Dx04A%2F83a2DdRiTdsTcHZ8eqNLcE5RL9wxlg9K2ONv14mYqp8ecoKqfba%2FYdOngfIcnam%2F6hBO4MLsGrdwTQ2m9nYflAGmPAkvYe%2BJbzsCwb%2BTw6StyqUTkaD9BjjDDl2JBhd5RcVJR1aFIYT9FMQfPsnUrFXABx1zLc%2FB%2FCggM6ugRqVIw8%2FE8SLrnuyw0pVHw%2F1oqI%2BalvqaKVfJEhlUtkzmqxqjvdLu9kOw%3D%3D"}}}
        //获取支付签名字符串
        $demo_arr = $paymentService->ali_app($data1, $data2);

        $pay_sign_str = $demo_arr['payInfo'];

        $arr = explode("&", $pay_sign_str);
        $arr = array_map(function ($v) {
            return explode("=", $v);
        }, $arr);
        $total_arr = [];
        foreach ($arr as $k => $v) {
            $total_arr[$v[0]] = $v[1];
        }
        if (empty($pay_sign_str)) {
            //return $this->exitJson(1, "返回支付签名失败");
            return ['errno' => 1, 'message' => "返回支付签名失败"];
        } else {
            $data = ['pay_sign_str' => $pay_sign_str, 'str' => json_encode($total_arr, JSON_UNESCAPED_UNICODE)];
            //return $this->exitJson(0, '返回支付签名成功', ['info'=>$data]);
            return ['errno' => 0, 'message' => "返回支付签名成功", 'info' => $data];
        }
    }

    public function aliPay2() {
        //获取支付宝配置参数
        $config = Db::name("config")->where("id", 1)->find();
        //支付宝支付
        $zfb_appid = trim($config['zfb_appid']);
        //开发者私钥
        $zfb_develop_private_key = trim($config['zfb_develop_private_key']);
        //支付宝公钥
        $zfb_public_key = trim($config['zfb_public_key']);

        //支付宝回调地址 site_root返回当前域名
        //$notify_url = SITE_ROOT.'/api/pay/aliNotify';
        $notify_url = url("api/pay/aliNotify2", '', false, true);

        $data1 = [
            'goodsName' => $this->subject,
            'orderId' => $this->orderID,
            'payPrice' => $this->payAmount,
            'notify_url' => $notify_url,
        ];
        $data2 = [
            'alipay_appid' => $zfb_appid,
            'alipay_privateKey' => $zfb_develop_private_key,
            'alipay_publicKey' => $zfb_public_key
        ];
        $paymentService = new PaymentService($this->orderID);
        //{"code":0,"msg":"返回支付签名成功","data":{"info":{"pay_sign_str":"alipay_sdk=alipay-sdk-php-20161101&app_id=2016082201785095&biz_content=%7B%22body%22%3A%22%E6%A2%A6%E5%A6%86%E5%A5%97%E8%A3%85%E6%B0%B4%E4%BB%99%E8%8A%B1%E6%B0%B4%E4%B9%B3%E9%9D%A2%E9%9C%9C%E6%8A%A4%E8%82%A4%E5%93%81%E5%A5%97%E8%A3%85%E8%A1%A5%E6%B0%B4%E4%BF%9D%E6%B9%BF%E4%BF%AE%E6%8A%A4%E8%88%92%E7%BC%93%E5%A5%B3%E5%8C%96%E5%A6%86%E5%93%81%E6%AD%A3%E5%93%81%22%2C%22subject%22%3A+%22%E6%A2%A6%E5%A6%86%E5%A5%97%E8%A3%85%E6%B0%B4%E4%BB%99%E8%8A%B1%E6%B0%B4%E4%B9%B3%E9%9D%A2%E9%9C%9C%E6%8A%A4%E8%82%A4%E5%93%81%E5%A5%97%E8%A3%85%E8%A1%A5%E6%B0%B4%E4%BF%9D%E6%B9%BF%E4%BF%AE%E6%8A%A4%E8%88%92%E7%BC%93%E5%A5%B3%E5%8C%96%E5%A6%86%E5%93%81%E6%AD%A3%E5%93%81%22%2C%22out_trade_no%22%3A+%22a46609234af4d2b3%22%2C%22timeout_express%22%3A+%2230m%22%2C%22total_amount%22%3A+%22100.00%22%2C%22product_code%22%3A%22QUICK_MSECURITY_PAY%22%7D&charset=UTF-8&format=json&method=alipay.trade.app.pay&notify_url=localhost%2Fapi%2Fpay%2FaliNotify&sign_type=RSA2&timestamp=2018-06-22+11%3A47%3A30&version=1.0&sign=O74JEgf8ajo9LeJ6T41g%2FTcfZ5uw1VKNaxUeadRgWQylex0gQXziMCFEfdJ88HXJ%2BDbXdyDM2zp7GCziwHPUVdPfpK4ajFvg7n66w6w65PJmngm5Dx04A%2F83a2DdRiTdsTcHZ8eqNLcE5RL9wxlg9K2ONv14mYqp8ecoKqfba%2FYdOngfIcnam%2F6hBO4MLsGrdwTQ2m9nYflAGmPAkvYe%2BJbzsCwb%2BTw6StyqUTkaD9BjjDDl2JBhd5RcVJR1aFIYT9FMQfPsnUrFXABx1zLc%2FB%2FCggM6ugRqVIw8%2FE8SLrnuyw0pVHw%2F1oqI%2BalvqaKVfJEhlUtkzmqxqjvdLu9kOw%3D%3D"}}}
        //获取支付签名字符串
        $demo_arr = $paymentService->ali_app($data1, $data2);

        $pay_sign_str = $demo_arr['payInfo'];

        $arr = explode("&", $pay_sign_str);
        $arr = array_map(function ($v) {
            return explode("=", $v);
        }, $arr);
        $total_arr = [];
        foreach ($arr as $k => $v) {
            $total_arr[$v[0]] = $v[1];
        }
        if (empty($pay_sign_str)) {
            //return $this->exitJson(1, "返回支付签名失败");
            return ['errno' => 1, 'message' => "返回支付签名失败"];
        } else {
            $data = ['pay_sign_str' => $pay_sign_str, 'str' => json_encode($total_arr, JSON_UNESCAPED_UNICODE)];
            //return $this->exitJson(0, '返回支付签名成功', ['info'=>$data]);
            return ['errno' => 0, 'message' => "返回支付签名成功", 'info' => $data];
        }
    }

    public function wxPay() {

        $config = Db::name("config")->where("id", 1)->find();
        $total_arr = [];

        $wx_appid = $config['appid'];
        $wx_mchid = $config['mchid'];
        $wx_signkey = $config['signkey'];
        if (empty($wx_appid) || empty($wx_mchid) || empty($wx_signkey)) {
            throw new \Exception('微信支付相关参数不完整');
        }

        $notify_url = url("api/pay/wechatNotify", '', false, true);


        $data1 = [
            'goodsName' => $this->subject,
            'orderId' => $this->orderID,
            'payPrice' => $this->payAmount,
            'notify_url' => $notify_url,
        ];

        $data2 = [
            'appid' => $wx_appid,
            'mchid' => $wx_mchid,
            'signkey' => $wx_signkey,
        ];

        $paymentService = new PaymentService($this->orderID);

        //获取微信签名字符串
        $demo_arr = $paymentService->wechat_app($data1, $data2);

        $pay_sign_str = json_encode($demo_arr, JSON_UNESCAPED_UNICODE);
        if (empty($pay_sign_str)) {
            return ['errno' => 1, 'message' => "返回支付签名失败"];
        } else {
            $data = ['pay_sign_str' => $pay_sign_str, 'str' => json_encode($total_arr, JSON_UNESCAPED_UNICODE)];
            return ['errno' => 0, 'message' => "返回支付签名成功", 'info' => $data];
        }
    }

    public function wxPay2() {

        $config = Db::name("config")->where("id", 1)->find();
        $total_arr = [];

        $wx_appid = $config['appid'];
        $wx_mchid = $config['mchid'];
        $wx_signkey = $config['signkey'];
        if (empty($wx_appid) || empty($wx_mchid) || empty($wx_signkey)) {
            return $this->exitJson(1, '微信支付相关参数不完整');
        }

        $notify_url = url("api/pay/wechatNotify2", '', false, true);


        $data1 = [
            'goodsName' => $this->subject,
            'orderId' => $this->orderID,
            'payPrice' => $this->payAmount,
            'notify_url' => $notify_url,
        ];

        $data2 = [
            'appid' => $wx_appid,
            'mchid' => $wx_mchid,
            'signkey' => $wx_signkey,
        ];

        $paymentService = new PaymentService($this->orderID);

        //获取微信签名字符串
        $demo_arr = $paymentService->wechat_app($data1, $data2);

        $pay_sign_str = json_encode($demo_arr, JSON_UNESCAPED_UNICODE);
        if (empty($pay_sign_str)) {
            return ['errno' => 1, 'message' => "返回支付签名失败"];
        } else {
            $data = ['pay_sign_str' => $pay_sign_str, 'str' => json_encode($total_arr, JSON_UNESCAPED_UNICODE)];
            return ['errno' => 0, 'message' => "返回支付签名成功", 'info' => $data];
        }
    }

    /**
     * 赠送类型 优惠券  核销二维码
     */
    static function coupon_qrcode_image($shopid, $couponid, $customerid) {
        $filename = "upload/coupon/{$shopid}_{$couponid}_{$customerid}.jpg";
        if (file_exists($filename)) {
            //return $filename;
        } else {
            $url = cmf_get_domain() . "/api/offline/offlinedescoupon/shopid/{$shopid}/couponid/{$couponid}/customerid/{$customerid}";
            QRcode::png($url, $filename, 'L', 5);
        }
        return $filename;
    }

    /**
     * 生成我的邀请 二维码
     */
    static function get_qrcode_image($code) {
        $filename = "upload/share/{$code}.jpg";
        if (file_exists($filename)) {
            //return $filename;
        } else {
            $url = cmf_get_domain() . "/api/share/goodsShare/code/" . $code;
            QRcode::png($url, $filename, 'L', 6, 1);
        }
        return $filename;
    }

    /**
     * 生成我的邀请 二维码
     */
    static function get_qrcode_image_shop($shopid, $code) {
        $filename = "upload/share/{$code}_{$shopid}.jpg";
        if (file_exists($filename)) {
            //return $filename;
        } else {
            $url = cmf_get_domain() . "/api/share/shopinfo/code/" . $code . "/shopid/" . $shopid;
            QRcode::png($url, $filename, 'L', 6, 1);
        }
        return $filename;
    }

    /**
     * 我的邀请合成图片
     * $qrcode_path 二维码文件路径
     * $code 用户邀请码
     * $img_url 背景图片地址
     * $k 生成的图片名称末尾，防止同一个用户多个邀请图片造成的图片同名覆盖
     */
    static function get_water_image($qrcode_path, $code, $img_url, $k = 0) {


        //如果文件不存在，则下载图片到本地
        $img_url = self::downloadImage($img_url, "upload/share/");

        $ttf = "static/font/st.otf";

        $image1 = \think\Image::open($img_url);

        $im1width = $image1->width();
        $im1height = $image1->height();

        //二维码长宽
        $qrimg = \think\Image::open($qrcode_path);
        $qrimgwidth = $qrimg->width();
        $qrimgheight = $qrimg->height();

        //邀请码长宽
        //         $img2 = \think\Image::open('static/images/invite_base1.png');
        //         $img2width=$img2->width();
        //         $img2height=$img2->height();

        //         //$location1 = [26, 20];
        //         // 邀请码底图
        //         $location1 = [($im1width-$img2width)/2,($im1height-$img2height)/2];
        //邀请码文字
        $location2 = [($im1width / 2) - 170, ($im1height / 2) + 140]; //邀请码

        $location7 = [($im1width - $qrimgwidth) / 2, $im1height - $qrimgheight - 50];
        //$location7 = [280,800];//二维码网址

        $invitation_image_url = "upload/share/invitation_{$code}_{$k}.jpg";

        $image22 = 'static/images/invite_base1.png';


        $image1->text("邀请码:" . $code, $ttf, 30, '#283645', $location2)
            ->water($qrcode_path, $location7)
            ->save($invitation_image_url, 'jpg', 100);


        //返回合成图片地址
        return $invitation_image_url;
    }

    /*
     *功能：php完美实现下载远程图片保存到本地
     *参数：文件url,保存文件目录,保存文件名称，使用的下载方式
     *当保存文件名称为空时则使用远程文件原来的名称
     */
    static function getImage($url, $save_dir = '', $filename = '', $type = 0) {
        if (trim($url) == '') {
            return array('file_name' => '', 'save_path' => '', 'error' => 1);
        }
        if (trim($save_dir) == '') {
            $save_dir = './';
        }
        if (trim($filename) == '') { //保存文件名
            $ext = strrchr($url, '.');
            if ($ext != '.gif' && $ext != '.jpg') {
                return array('file_name' => '', 'save_path' => '', 'error' => 3);
            }
            $filename = time() . $ext;
        }
        if (0 !== strrpos($save_dir, '/')) {
            $save_dir .= '/';
        }
        //创建保存目录
        if (!file_exists($save_dir) && !mkdir($save_dir, 0777, true)) {
            return array('file_name' => '', 'save_path' => '', 'error' => 5);
        }
        //获取远程文件所采用的方法
        if ($type) {
            $ch = curl_init();
            $timeout = 5;
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            $img = curl_exec($ch);
            curl_close($ch);
        } else {
            ob_start();
            readfile($url);
            $img = ob_get_contents();
            ob_end_clean();
        }
        //$size=strlen($img);
        //文件大小
        $fp2 = @fopen($save_dir . $filename, 'a');
        fwrite($fp2, $img);
        fclose($fp2);
        unset($img, $url);
        return array('file_name' => $filename, 'save_path' => $save_dir . $filename, 'error' => 0);
    }

    // 下载图片到本地
    static function downloadImage($img_url, $path = '') {
        if (!file_exists($path . basename($img_url))) {
            self::getImage($img_url, $path, basename($img_url));
        }
        return $path . basename($img_url);
    }

    /**
     * 支付总入口
     * @param $uid 用户id
     * @param $order_id 订单id
     * @param $orderNo 订单号
     * @param $payAmount 支付金额
     * @param $payType 支付模式 1微信 2支付宝
     * @param string $subject 支付描述
     * @return mixed
     * @throws \Exception
     */
    static public function pay($uid, $order_id, $orderNo, $payAmount, $payType, $subject = '留莲忘返-商品消费', $order_type = 0) {
        if ($payType != 1 && $payType != 2 && $payType != 3) throw new \Exception('支付方式不正确!');
        //支付信息
        $pay = [];
        $pay['order_no'] = $orderNo;
        $pay['order_id'] = $order_id;
        $pay['create_time'] = date('Y-m-d H:i:s');
        $pay['pay_status'] = 2;
        $pay['pay_amount'] = $payAmount;
        $pay['pay_type'] = $payType;
        $pay['uid'] = $uid;
        $pay['order_type'] = $order_type;
        try {
            Db::startTrans();
            if (Db::name('pay_info')->where('order_id', $order_id)->where('order_no', $orderNo)->where('uid', $uid)->count('id') > 0) {
                Db::name('pay_info')->where('order_id', $order_id)->where('order_no', $orderNo)->where('uid', $uid)->update(['pay_type' => $payType, 'pay_amount' => $payAmount]);
            } else {
                Db::name('pay_info')->insert($pay);
            }
            $self = new self($orderNo, $payAmount, $subject);
            $res = null;
            if ($payType == 1) {
                $res = $self->wxPay();
            } elseif ($payType == 2) {
                $res = $self->aliPay();
            } elseif ($payType == 3) {
                $wxmini = new WxMiniPayService();
                $openId = Db::name('members_wechat')->where('mid', $uid)->value('mini_openid');
                if (empty($openId)) throw new \Exception('请授权登陆后重试');
                $res['info'] = $wxmini->wxPay([
                    'order_no' => $orderNo,
                    'total_fee' => $payAmount,
                    'desc' => $subject,
                    'openId' => $openId
                ]);
            }
            if (!$res) throw new \Exception('系统错误!');
            if (!empty($res['errno'])) throw new \Exception($res['message']);
            Db::commit();
            return $res['info'];
        } catch (\Exception $e) {
            Db::rollback();
            Db::name('error_log')->insert([
                'source' => 'orderService/pay',
                'desc' => '文件:' . $e->getFile() . ';位置:' . $e->getLine() . ';原因:' . $e->getMessage(),
                'uid' => $uid,
                'order' => $orderNo,
                'date' => date('Y-m-d H:i:s')
            ]);
            throw new \Exception($e->getMessage());
        }
    }
}
