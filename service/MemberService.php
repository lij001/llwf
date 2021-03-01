<?php


namespace app\api\service;


use app\api\BaseService;
use app\api\model\Member;
use app\api\model\member\MemberAddress;
use app\api\model\member\MemberSmrz;
use app\api\model\member\MemberWithdraw;
use think\Cache;

class MemberService extends BaseService {
    public function getMemberList($param, $field = null) {
        $model = Member::objectInit();
        if (!empty($param['ids'])) {
            $model->whereIn('id', $param['ids']);
        }
        if ($field !== null) {
            $field = 'id,' . $field;
            $model->field($field);
        }
        return $model->select();
    }

    public static function getCurrentMid() {
        $uid = TokenService::getCurrentUid();
        return $uid;
    }

    public static function getMemberInfo($mid) {
        $model = Member::objectInit();
        if (is_array($mid)) {
            if (empty($mid)) throw new \Exception('参数不能为空');
            $model->where($mid);
        } else {
            if ($mid < 1) throw new \Exception('用户id不能为空!');
            $model->where('id', $mid);
        }
        $m = $model->find();
        if ($m === null) throw new \Exception('未找到用户信息!');
        return $m;
    }

    /**
     * 绑定提现账户
     * @param $param
     * @return int
     * @throws \think\exception\DbException
     */
    public function binWithdraw($param) {
        if (empty($param['type'])) throw new \Exception('类型不能为空!');
        if (empty($param['mobile'])) throw new \Exception('手机号码不能为空!');
        if (empty($param['name'])) throw new \Exception('名称不能为空');
        if (empty($param['code'])) throw new \Exception('验证码不能为空');
        $IsCode = IsCode(trim($param['code']), $param['mobile']);
        if (!empty($IsCode)) {
            throw new \Exception($IsCode);
        }
        $param['mid'] = MemberService::getCurrentMid();
        switch ($param['type']) {
            case '微信':
                $this->binWithdrawWeixin($param);
                break;
            case '支付宝':
                $this->binWithdrawAlipay($param);
                break;
            default:
        }
        $info = MemberWithdraw::_whereCV(['mid' => $param['mid'], 'type' => $param['type']])->find();
        if ($info === null) {
            MemberWithdraw::create($param, true);
        } else {
            $info['account'] = $param['account'];
            $info['name'] = $param['name'];
            $info->save();
        }
        return 1;
    }

    private function binWithdrawWeixin(&$param) {
        if (empty($param['openid'])) throw new \Exception('openid不能为空!');
        $param['account'] = $param['openid'];
        return 1;
    }

    private function binWithdrawAlipay(&$param) {
        if (empty($param['alipay'])) throw new \Exception('alipay不能为空!');
        $param['account'] = $param['alipay'];
        return 1;
    }

    /**
     * 提现账户列表-分页
     * @param $param
     * @return \think\Paginator
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function withdrawListPage($param) {
        $model = MemberWithdraw::objectInit();
        if (!empty($param['mid'])) {
            $model->where('mid', $param['mid']);
        }
        if (!empty($param['keyword'])) {
            if (mb_strlen($param['keyword'], 'utf8') === 11) {
                $info = Member::where('mobile', $param['keyword'])->find();
                if ($info === null) {
                    $model->where('id', 0);
                } else {
                    $model->where('mid', $info['id']);
                }
            } else {
                $model->where('name', $param['keyword']);
            }

        }
        if (!empty($param['type'])) {
            $model->whereCV('type', $param['type']);
        }
        if (!empty($param['white_list'])) {
            $model->whereCV('white_list', $param['white_list']);
        }
        $model->order('id', 'desc');
        $model->with('member');
        return $model->paginate(20);
    }

    /**
     * 获取提现账户有多少类型
     * @return string[]
     */
    public function getWithdrawTypeList() {
        return array_values(MemberWithdraw::objectInit()->getTypeList());
    }

    /**
     * 更新提现账户信息
     * @param $param
     */
    public function updateWithdraw($param) {
        if (empty($param['id'])) throw new \Exception('id不能为空!');
        MemberWithdraw::where('id', $param['id'])->allowField(true)->update($param);
        return 1;
    }

    /**
     * 检测用户是否实名认证了-未实名认证抛异常
     * @param $mid
     */
    public function checkMemberSMRZ($mid) {
        $info = MemberSmrz::_whereCV(['uid' => $mid, 'status' => '通过'])->find();
        if ($info === null) {
            throw new \Exception('您还未实名认证,请先实名认证!');
        }
        return 1;
    }

    public function getWithdrawInfo($id, $mid = 0) {
        $model = MemberWithdraw::where('id', $id);
        if ($mid) {
            $model->where('mid', $mid);
        }
        $info = $model->find();
        if ($info === null) {
            throw new \Exception('未找到提现账户!');
        }
        return $info;
    }

    public function getMemberSmrz($mid) {
        $info = MemberSmrz::_whereCV(['uid' => $mid, 'status' => '通过'])->find();
        if ($info === null) {
            throw new \Exception('未找到用户实名认证信息');
        }
        return $info;
    }

    public function getMemberWithdrawAccountList($mid) {
        $list = MemberWithdraw::where('mid', $mid)->select();
        return $list;
    }

    public function getMemberAliWithdrawAccount($mid) {
        $alipay = MemberWithdraw::where('mid', $mid)->where('type', 2)->find();
        return $alipay;
    }

    public function getAddress($mid, $address_id) {
        return MemberAddress::_whereCV(['id' => $address_id, 'uid' => $mid])->find();
    }

    public function getParentId(string $mid) {
        $info = Member::_whereCV('id', $mid)->find();
        if ($info === null) throw new \Exception('错误未找到会员信息!');
        return $info['parent_id'];
    }

    public function migration($param) {
        if (empty($param['mid1']) || empty($param['mid2'])) throw new \Exception('不能有空的id!');
        if ($param['mid1'] == $param['mid2']) throw new \Exception('id不能相同');
        $a = Member::_whereCVIn('id', [$param['mid1'], $param['mid2']])->select();
        $member = null;
        $merge_member = null;
        foreach ($a as $item) {
            if (strpos($item['mobile'], 'merge')) {
                $merge_member = $item;
            } else {
                $member = $item;
            }
        }
        if ($member === null || $merge_member === null) throw new \Exception('未找到另一个账号');
        if (strpos($merge_member['mobile'], $member['mobile']) === false) throw new \Exception('这两个账户并不是对应的');
        $editMembers = Member::objectInit()->whereLike('path', '%' . $merge_member['id'] . '-' . $merge_member['path'])->select();
        $memberPath = $member['id'] . '-' . $member['path'];
        foreach ($editMembers as $item) {
            if ($item['parent_id'] === $merge_member['id']) {
                $item['parent_id'] = $member['id'];
            }
            $item['path'] = explode($merge_member['id'], $item['path'])[0] . $memberPath;
            $item->save();
        }
        return 1;
    }

}


