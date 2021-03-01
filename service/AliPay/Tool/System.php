<?php

namespace app\api\service\AliPay\Tool;

use app\api\service\AliPay\Kernel\BaseClient;

class System extends BaseClient
{
    /**
     * 换取授权访问令牌
     * @param array $options
     * @param string $auth_token
     * @return array|bool|mixed
     * @throws \app\api\service\AliPay\Kernel\Exceptions\InvalidResponseException
     * @throws \app\api\service\AliPay\Kernel\Exceptions\LocalCacheException
     */
    public function apply($options)
    {
        $this->options->set('method', 'alipay.system.oauth.token');
        $this->options->set('grant_type', $options['grant_type']);
        $this->options->set('code', $options['code']);
        $this->options->set('sign_type', 'RSA');

        return $this->getResult($options,'POST');
    }
}
