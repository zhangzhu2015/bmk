<?php

if (!function_exists('demo1')) {

    function demo1()
    {
        return 'demo1';
    }

}

/***
 * 图片地址处理
 */

function getImgDir($img,$height=216,$weight=216)
{
    if($height && $weight){
        return env('IMG_HOST').$img.'?x-oss-process=image/resize,m_pad,h_'.$height.',w_'.$weight;
    }

    if(!$height || !$weight){
        return env('IMG_HOST').$img;
    }
}


/***
 * 手机号码显示
 */
function showMobile($mobile){
    if(strlen($mobile) == 10 && strpos($mobile,'9')==0){
        $mobile = '0'.$mobile;
    }
    return $mobile;
}

function showPrice($price)
{
    return number_format($price,2,".","");
}

/**
 * 解析json串
 * @param type $json_str
 * @return type
 */
function analyJson($json_str) {
    $json_str = str_replace('＼＼', '', $json_str);
    $out_arr = array();
    preg_match('/{.*}/', $json_str, $out_arr);
    if (!empty($out_arr)) {
        $result = json_decode($out_arr[0], TRUE);
    } else {
        return FALSE;
    }
    return $result;
}

//判断商家的合法性
function is_valid_seller($seller_id)
{
    $sellerRow = \Illuminate\Support\Facades\DB::table('seller')->where('id', $seller_id)->first();
    if($sellerRow->is_del != 0 || $sellerRow->is_lock != 0){
        return false;
    }else{
        return true;
    }
}

/**
 * @param $goods_id
 * @return bool
 * Author: wangding
 * CreateTime: 2018/2/22 14:57
 * Description: 检查是否是flash  true 是 有一个商品是flash
 */
function isFlash($goods_id) {
    return \Illuminate\Support\Facades\DB::table('promotion')
        ->when(is_array($goods_id), function($query) use ($goods_id) {
            return $query->whereIn('condition', $goods_id);
        }, function($q) use ($goods_id) {
            return $q->where('condition', $goods_id);
        })
        ->where([
            ['type', 1],
            ['is_close', 0],
            ['end_time', '>=', DB::raw('now()')],
            ['start_time', '<=', DB::raw('now()')],
        ])->exists();
}


/**
 * @param $products_id
 * @return bool
 * Author: wangding
 * CreateTime: 2018/2/24 15:38
 * Description: 检查商品的规格存不存在  true 存在规格
 */
function isProduct($products_id){
    return \Illuminate\Support\Facades\DB::table('products')
        ->where('id', $products_id)->exists();
}

/**
 * @param $goods_id
 * @return bool
 * Author: wangding
 * CreateTime: 2018/2/24 15:38
 * Description: 检查商品是否被删除  true 代表有一个商品是呗删除的
 */
function isDelGoods($goods_id){
    return \Illuminate\Support\Facades\DB::table('goods')
        ->when(is_array($goods_id), function($query) use ($goods_id) {
            return $query->whereIn('id', $goods_id);
        }, function($q) use ($goods_id) {
            return $q->where('id', $goods_id);
        })
        ->where('is_del', '>', 0)->exists();
}



if (!function_exists('getNewOrderStatusText')) {

    function getNewOrderStatusText($order_status){
        if(!$order_status){
            $order_status = 0;
        }
        //订单状态
        $arr = [
            '0' => 'Unknown',
            '1' => 'Undelivery',
            '2' => 'Unpaid',
            '3' => 'In transit',
            '4' => 'Undelivery',
            '5' => 'Canceled',
            '6' => 'Completed',
            '7' => 'Refunded',
            '8' => 'Part shipping',
            '9' => 'Part shipping',
            '10'=> 'Partial refund',
            '11'=> 'In transit',
            '12'=> 'Request refund',
            '13'=> 'Cancel Request',
            '14'=> 'Waiting for audit',
            '15'=> 'Refund failed',
            '16'=> 'Refund success',
            '17'=> 'Wait for pick up',
            '18'=> 'pick up',
        ];
        return $arr[$order_status];
    }

}



if (!function_exists('get_address_name')) {

    /**
     * @brief 根据传入的地域ID获取地域名称，获取的名称是根据ID依次获取的
     * @param int 地域ID 匿名参数可以多个id
     * @return array
     */
    function get_address_name()
    {
        $result     = array();
        $paramArray = func_get_args();
        $areaData   = \Illuminate\Support\Facades\DB::table('areas')->whereRaw("area_id in (".trim(join(',',$paramArray),",").")")->get();

        foreach($areaData as $key => $value)
        {
            $result[$value->area_id] = $value->area_name;
        }
        return collect($result);
    }


}

/**
 * $string 明文或密文
 * $operation 加密ENCODE或解密DECODE
 * $key 密钥
 * $expiry 密钥有效期
 */
function authcode($string, $operation = 'DECODE', $key = '', $expiry = 0)
{
    // 动态密匙长度，相同的明文会生成不同密文就是依靠动态密匙
    // 加入随机密钥，可以令密文无任何规律，即便是原文和密钥完全相同，加密结果也会每次不同，增大破解难度。
    // 取值越大，密文变动规律越大，密文变化 = 16 的 $ckey_length 次方
    // 当此值为 0 时，则不产生随机密钥
    $ckey_length = 4;

    // 密匙
    // $GLOBALS['discuz_auth_key'] 这里可以根据自己的需要修改
    $key = md5($key ? $key : $GLOBALS['discuz_auth_key']);

    // 密匙a会参与加解密
    $keya = md5(substr($key, 0, 16));
    // 密匙b会用来做数据完整性验证
    $keyb = md5(substr($key, 16, 16));
    // 密匙c用于变化生成的密文
    $keyc = $ckey_length ? ($operation == 'DECODE' ? substr($string, 0, $ckey_length): substr(md5(microtime()), -$ckey_length)) : '';
    // 参与运算的密匙
    $cryptkey = $keya.md5($keya.$keyc);
    $key_length = strlen($cryptkey);
    // 明文，前10位用来保存时间戳，解密时验证数据有效性，10到26位用来保存$keyb(密匙b)，解密时会通过这个密匙验证数据完整性
    // 如果是解码的话，会从第$ckey_length位开始，因为密文前$ckey_length位保存 动态密匙，以保证解密正确
    $string = $operation == 'DECODE' ? base64_decode(substr($string, $ckey_length)) : sprintf('%010d', $expiry ? $expiry + time() : 0).substr(md5($string.$keyb), 0, 16).$string;
    $string_length = strlen($string);
    $result = '';
    $box = range(0, 255);
    $rndkey = array();
    // 产生密匙簿
    for($i = 0; $i <= 255; $i++) {
        $rndkey[$i] = ord($cryptkey[$i % $key_length]);
    }
    // 用固定的算法，打乱密匙簿，增加随机性，好像很复杂，实际上并不会增加密文的强度
    for($j = $i = 0; $i < 256; $i++) {
        $j = ($j + $box[$i] + $rndkey[$i]) % 256;
        $tmp = $box[$i];
        $box[$i] = $box[$j];
        $box[$j] = $tmp;
    }
    // 核心加解密部分
    for($a = $j = $i = 0; $i < $string_length; $i++) {
        $a = ($a + 1) % 256;
        $j = ($j + $box[$a]) % 256;
        $tmp = $box[$a];
        $box[$a] = $box[$j];
        $box[$j] = $tmp;
        // 从密匙簿得出密匙进行异或，再转成字符
        $result .= chr(ord($string[$i]) ^ ($box[($box[$a] + $box[$j]) % 256]));
    }
    if($operation == 'DECODE') {
        // substr($result, 0, 10) == 0 验证数据有效性
        // substr($result, 0, 10) - time() > 0 验证数据有效性
        // substr($result, 10, 16) == substr(md5(substr($result, 26).$keyb), 0, 16) 验证数据完整性
        // 验证数据有效性，请看未加密明文的格式
        if((substr($result, 0, 10) == 0 || substr($result, 0, 10) - time() > 0) && substr($result, 10, 16) == substr(md5(substr($result, 26).$keyb), 0, 16)) {
            return substr($result, 26);
        } else {
            return '';
        }
    } else {
        // 把动态密匙保存在密文里，这也是为什么同样的明文，生产不同密文后能解密的原因
        // 因为加密后的密文可能是一些特殊字符，复制过程可能会丢失，所以用base64编码
        return $keyc.str_replace('=', '', base64_encode($result));
    }
}

/**
 * @param array $arr
 * @return \stdClass
 * Description: 数组转成集合
 */
function arrayToCollect(array $arr)
{
    $c = new \stdClass();
    foreach($arr as $k => $v){
        $c->$k = $v;
    }
    return $c;
}


if (!function_exists('encode')) {

//购物车存储数据编码
    function encode($data)
    {
        return str_replace(array('"', ','), array('&', '$'), json_encode($data));
    }
}

if (!function_exists('decode')) {

//购物车存储数据解码
    function decode($data)
    {
        return json_decode(str_replace(array('&', '$'), array('"', ','), $data), true);
    }
}

/**
 * @param array $arr
 * @param array $hide
 * @return array
 * Author: chenglun
 * CreateTime: 2018/7/26
 * Description: 隐藏返回的字段
 */
function hideFields($arr, array $hide) {
    return collect($arr)->forget($hide)->all();
}

/**
 * 获取客户端IP地址
 * @param integer $type 返回类型 0 返回IP地址 1 返回IPV4地址数字
 * @param boolean $adv 是否进行高级模式获取（有可能被伪装）
 * @return mixed
 */
function get_client_ip($type = 0,$adv=false) {
    $type       =  $type ? 1 : 0;
    static $ip  =   NULL;
    if ($ip !== NULL) return $ip[$type];
    if($adv){
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $arr    =   explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $pos    =   array_search('unknown',$arr);
            if(false !== $pos) unset($arr[$pos]);
            $ip     =   trim($arr[0]);
        }elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip     =   $_SERVER['HTTP_CLIENT_IP'];
        }elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip     =   $_SERVER['REMOTE_ADDR'];
        }
    }elseif (isset($_SERVER['REMOTE_ADDR'])) {
        $ip     =   $_SERVER['REMOTE_ADDR'];
    }
    // IP地址合法验证
    $long = sprintf("%u",ip2long($ip));
    $ip   = $long ? array($ip, $long) : array('0.0.0.0', 0);
    return $ip[$type];
}


function show_spec($specJson)
{
    $specArray = json_decode($specJson,true);
    $spec      = array();

    foreach($specArray as $val)
    {
        if($val['type'] == 1)
        {
            $spec[$val['name']] = $val['value'];
        }
        else
        {
            $spec[$val['name']] = '<img src="'.env('IMG_HOST').$val['value'].'" class="img_border" style="width:15px;height:15px;" />';
        }
    }
    return $spec;
}

/**
 * @brief 获取字符个数
 * @param string 被计算个数的字符串
 * @return int 字符个数
 */
function getStrLen($str)
{
    $byte   = 0;
    $amount = 0;
    $str    = trim($str);

    //获取字符串总字节数
    $strlength = strlen($str);

    //检测是否为utf8编码
    $isUTF8=isUTF8($str);

    //utf8编码
    if($isUTF8 == true)
    {
        while($byte < $strlength)
        {
            if(ord($str{$byte}) >= 224)
            {
                $byte += 3;
                $amount++;
            }
            else if(ord($str{$byte}) >= 192)
            {
                $byte += 2;
                $amount++;
            }
            else
            {
                $byte += 1;
                $amount++;
            }
        }
    }

    //非utf8编码
    else
    {
        while($byte < $strlength)
        {
            if(ord($str{$byte}) > 160)
            {
                $byte += 2;
                $amount++;
            }
            else
            {
                $byte++;
                $amount++;
            }
        }
    }
    return $amount;
}

/**
 * @brief 检测编码是否为utf-8格式
 * @param string $word 被检测的字符串
 * @return bool 检测结果 值: true:是utf8编码格式; false:不是utf8编码格式;
 */
function isUTF8($word)
{
    if(extension_loaded('mbstring'))
    {
        return mb_check_encoding($word,'UTF-8');
    }
    else
    {
        if(preg_match("/^([".chr(228)."-".chr(233)."]{1}[".chr(128)."-".chr(191)."]{1}[".chr(128)."-".chr(191)."]{1}){1}/",$word) == true || preg_match("/([".chr(228)."-".chr(233)."]{1}[".chr(128)."-".chr(191)."]{1}[".chr(128)."-".chr(191)."]{1}){1}$/",$word) == true || preg_match("/([".chr(228)."-".chr(233)."]{1}[".chr(128)."-".chr(191)."]{1}[".chr(128)."-".chr(191)."]{1}){2,}/",$word) == true)
        {
            return true;
        }
        return false;
    }
}