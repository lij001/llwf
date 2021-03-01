<?php


namespace app\api\service;


use think\Db;

class SmsService
{
    /**
     * 酒店短信通知
     * @param $order_no
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function hotelSms($order_no)
    {
        $order = Db::name('hotel_order')->where('order_no', $order_no)->find();
        if (mb_strlen($order['room_name'] . $order['room_type']) > 20) {
            $hotelname = $order['room_name'];
        } else {
            $hotelname = $order['room_name'] . $order['room_type'];
        }
        $param = [
            'order_no' => $order['orderId'],
            'datetime' => $order['checkIn'],
            'hotelname' => $hotelname,
            'money' => $order['pay_amount']
        ];
        $mobile = Db::name('members')->where('id', $order['uid'])->value('mobile');
        return send_sms_aliyun_new($mobile, $param, 'SMS_195196963');
    }

    /**
     * 机票短信通知
     * @param $order_no
     * @return bool|string[]
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function airSms($orderId)
    {
        $order = Db::name('airticket_order')->where('orderId', $orderId)->find();
        $param = [
            "orderId" => $orderId,
            "acctId" => '159082'
        ];
        $air = new AirTicketService();
        $reArr = $air->getOrderDetail($param);
        $name = $reArr['data']['orderInfo']['contactList'][0]['name'];
        $tel = $reArr['data']['orderInfo']['contactList'][0]['tel'];
        $param = [
            'flightinfo' => $order['airlineName'] . $order['flightNum'] . $order['seatType'],
            'realname' => $name
        ];
        return send_sms_aliyun_new($tel, $param, 'SMS_195226801');
    }
}