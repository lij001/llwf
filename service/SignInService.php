<?php


namespace app\api\service;


use app\api\BaseService;
use app\api\model\ConfigNew;
use app\api\model\SignIn;

class SignInService extends BaseService {
    public function signInList($param) {
        $mid = MemberService::getCurrentMid();
        $date = $param['start_date'] ?: date('Y-m-01', strtotime('-1 month'));
        $start_time = strtotime($date);
        if ($start_time === false) throw new \Exception('时间格式异常!');
        $list = SignIn::where(['mid' => $mid, 'create_time' => ['>=', $start_time]])->select();
        return $list;
    }

    public function signIn() {
        $mid = MemberService::getCurrentMid();
        //$mid = 1715;
        $time = strtotime(date('Y-m-d'));
        if (SignIn::where(['mid' => $mid, 'create_time' => ['>=', $time]])->find() !== null) throw new \Exception('今日已经签到过了!');
        $config = ConfigNew::getSignIn();
        $data = [
            'mid' => $mid,
            'create_time' => time(),
            'gxz' => $config['signIn'],
            'num' => 1
        ];
        try {
            SignIn::startTrans();
            $info = SignIn::where('mid', $mid)->order('id', 'desc')->find();
            if ($info !== null && $info['create_time'] >= strtotime('-1 day', $time)) {
                $data['num'] = $info['num'] + 1;
            }
            SignIn::create($data);
            MemberBalanceService::objectInit()->signInSuccess($mid, $data['num'], $config);
            SignIn::commit();
        } catch (\Exception $e) {
            SignIn::rollback();
            throw new \Exception($e->getMessage());
        }
        return 1;
    }

    /**
     * @param int $mid 合并主账号
     * @param int $mergeMid 合并副账号
     */
    public function mergeMember($mid, $mergeMid) {
        return SignIn::_whereCV('mid', $mergeMid)->update(['mid' => $mid]);
    }


}

