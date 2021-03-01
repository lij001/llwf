<?php

namespace app\api\service\Sdw;

use app\api\BaseService;

abstract class Request extends BaseService {
    const SDW_URL = 'http://www.shandw.com/auth/';
    const SDW_APP_KEY = 'cfa54a701d354cb8a313f34c237e9245';
    const SDW_API_KEY = '39f7b6b986a543fcb2a5d68cd49359d4';
    const SDW_CHANNEL_ID = '14129';
    const SDW_ACOUNT = 'llwf7676';
    const QUERY_GAME_GISTORY_BY_USER = 'https://h5gm2.shandw.com/open/channel/queryGameHistoryByUser';
    const QUERY_USER_GAME_RECORD = 'https://h5gm2.shandw.com/open/userGame/queryUserGameRecord';
    const QUERY_PAY_BY_CHANNEL = 'https://h5gm2.shandw.com/open/channel/queryPayByChannel';
}
