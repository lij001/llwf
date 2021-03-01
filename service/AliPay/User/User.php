<?php

namespace app\api\service\AliPay\User;

use app\api\service\AliPay\Kernel\BaseClient;

class User extends BaseClient
{
    /**
     * 支付宝会员授权信息查询接口
     * @param array $options
     * @param string $auth_token
     * @return array|bool|mixed
     * @throws \app\api\service\AliPay\Kernel\Exceptions\InvalidResponseException
     * @throws \app\api\service\AliPay\Kernel\Exceptions\LocalCacheException
     */
    public function apply($options)
    {
        $this->options->set('method', 'alipay.user.info.share');
        $this->options->set('auth_token', $options['auth_token']);
        $this->options->set('sign_type', 'RSA');

        return $this->getResult($options,'POST');
    }
}
