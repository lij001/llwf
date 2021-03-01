<?php

namespace app\api\service\AliPay\Tool;

use app\api\service\AliPay\Kernel\BaseClient;

class Open extends BaseClient
{
    /**
     * 换取授权访问令牌
     * @param array $options
     * @param string $app_auth_token
     * @return array|bool|mixed
     * @throws \app\api\service\AliPay\Kernel\Exceptions\InvalidResponseException
     * @throws \app\api\service\AliPay\Kernel\Exceptions\LocalCacheException
     */
    public function apply($options,$app_auth_token='')
    {
        $this->options->set('method', 'alipay.open.auth.token.app');
        $this->options->set('app_auth_token', $app_auth_token);

        return $this->getResult($options);
    }
}
