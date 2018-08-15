<?php

/**
* 公共方法
***/

// 是否是合法的手机号码
function isMobiles($mobile) {
	if (preg_match("/^((13|14|15|16|17|18)+\d{9})$/", $mobile)) {
		return true;
	} else {
		return false;
	}
}

//验证时间
function isDate($date) {
	$patten = "/^\d{4}[\-](0?[1-9]|1[012])[\-](0?[1-9]|[12][0-9]|3[01])(\s+(0?[0-9]|1[0-9]|2[0-3])\:(0?[0-9]|[1-5][0-9])\:(0?[0-9]|[1-5][0-9]))?$/";
	if (preg_match($patten, $date)) {
		return true;
	} else {
		return false;
	}
}

//是否是合法昵称(4-20个字符，支持中英文、数字、"_"或减号)
function isNickName($nickname) {
	$length = $this->utf8Strlen($nickname);
	if ($length<4 || $length>20) {
		return false;
	}
	$patten = "/^[\x{4e00}-\x{9fa5}A-Za-z0-9_-]+$/u";
	if (preg_match($patten, $nickname)) {
		return true;
	} else {
		return false;
	}
}

//是否是合法的密码(6-16位数字、字母或常用符号，字母区分大小写)
//需要转义的 *.?+$^[](){}|\/
function isPassWord($password) {
	$patten = "/^[A-Za-z0-9!@#\$%\^&~,\*\?\.\-\_]{6,16}$/";
	if (preg_match($patten, $password)) {
		return true;
	} else {
		return false;
	}
}

//是否是合法的验证码
function isVerifyCode($password) {
	$patten = "/^[0-9]{4}$/";
	if (preg_match($patten, $password)) {
		return true;
	} else {
		return false;
	}
}

/**
 * 统计utf8字符，中文按照2个字计算
 */
function utf8Strlen($str) {
	$count = 0;
	for ($i = 0; $i < strlen($str); $i++) {
		$value = ord($str[$i]);
		if ($value > 127) {
			$count++;
			if ($value >= 192 && $value <= 223)
				$i++;
			elseif ($value >= 224 && $value <= 239)
			$i = $i + 2;
			elseif ($value >= 240 && $value <= 247)
			$i = $i + 3;
		}
		$count++;
	}
	return $count;
}

/**
 * 统计utf8字符，中文按照1个字计算
 */
function strlenUtf8($str) {
	$i = 0;
	$count = 0;
	$len = strlen($str);
	while ($i < $len) {
		$chr = ord($str[$i]);
		$count++;
		$i++;
		if ($i >= $len)
			break;

		if ($chr & 0x80) {
			$chr <<= 1;
			while ($chr & 0x80) {
				$i++;
				$chr <<= 1;
			}
		}
	}
	return $count;
}

//utf-8中文截取
function substr($string, $length)
{
	$string = strip_tags($string);
	$string_strlen = mb_strlen($string, 'utf-8');
	if ($string_strlen>$length) {
	   return  mb_substr($string, 0, $length, 'utf-8').'......';
	}
	return $string;
}

// 使用curl模拟post
function curlPost($url, $data) {
	$curl = curl_init();
	curl_setopt($curl, CURLOPT_URL, $url);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl, CURLOPT_HEADER, false);
	curl_setopt($curl, CURLOPT_POST, true);
	curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
	$result = curl_exec($curl);
	curl_close($curl);
	return $result;
}

// 使用curl模拟get
function curlGet($url) {
	$curl = curl_init();
	curl_setopt($curl, CURLOPT_URL, $url);
	curl_setopt($curl, CURLOPT_HEADER, false);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	$result = curl_exec($curl);
	curl_close($curl);
	return $result;
}

// 获取用户IP
function getClientIp() {
	static $ip = NULL;
	if ($ip !== NULL){
		return $ip;
	}

	if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
		$arr = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
		$pos = array_search('unknown', $arr);
		if (false !== $pos){
			unset($arr[$pos]);
		}			
		$ip = trim($arr[0]);
	} else if (isset($_SERVER['HTTP_X_REAL_IP'])) {
		$ip = $_SERVER['HTTP_X_REAL_IP'];
	} else if (isset($_SERVER['HTTP_CLIENT_IP'])) {
		$ip = $_SERVER['HTTP_CLIENT_IP'];
	} else if (isset($_SERVER['REMOTE_ADDR'])) {
		$ip = $_SERVER['REMOTE_ADDR'];
	}
	// IP地址合法验证
	$ip = (false !== ip2long($ip)) ? $ip : '0.0.0.0';
	return $ip;
}

// 转换时间风格
function stylizeDate($the_date) {
	if (empty($the_date)) {
		return '1秒前';
	}
	$now_time = time();
	$the_time = strtotime($the_date);
	if ($now_time > $the_time) {
		$range = $now_time - $the_time;
		if ($range < 60) {
			return $range . '秒前';
		} elseif ($range < 3600) {
			return floor($range / 60) . '分钟前';
		} elseif ($range < 3600 * 24) {
			return floor($range / 3600) . '小时前';
		} elseif ($range < 3600 * 24 * 7) {
			return floor($range / 86400) . '天前';
		} else {
			if (date('Y') == date('Y', $the_time)) { // 当前年
				return date('m-d', $the_time);
			} else {
				return date('Y-m-d', $the_time);
			}
		}
	}
	return '1秒前';
}

//时间转化
function diffBetweenTwoDays ($day1, $day2,$input_comment)
{
  $second1 = $day1;
  $second2 = strtotime($day2);
  

 if ($second1 < $second2) {
   $tmp = $second2;
   $second2 = $second1;
   $second1 = $tmp;
   }
 return $input_comment.floor(($second1 - $second2) / 86400).'天';
}

// 格式化时间
function stylizePrice($price) {
	if ($price < 0) {
		return '0万';
	}
	return ($price / 10000) . '万';
}

//格式化浏览数
function stylizeViewCnt($view_cnt) {
	return (string)$view_cnt;
}

//格式化评论数
function stylizeCommentCnt($comment_cnt) {
	if ($comment_cnt>=1000) {
		return '999+';
	}   return (string)$comment_cnt;
}

//html转成文本
function html2Txt($str)
{
	if (empty($str)) return '';
	
	return preg_replace("/\n+/", "\n", str_replace(array(PHP_EOL, "\r", "\t"), "\n", trim(strip_tags($str))));
}

//根据日期计算年龄
function age($YTD)
{
	$YTD = strtotime($YTD);//int strtotime ( string $time [, int $now ] )
	$year = date('Y', $YTD);
	if(($month = (date('m') - date('m', $YTD))) < 0){
	$year++;
	}else if ($month == 0 && date('d') - date('d', $YTD) < 0){
	$year++;
	}
	return date('Y') - $year;
			
}

//数组value是null转换成空字符串
function array_null_tostring($arr)
{		
	foreach ($arr as $key => $value) {
		if($value == null){
			$arr[$key] = '';
		}
	}
	 return $arr;
}

/**
 * 检查变量是否设置或为空或空数组
 * @param $value  
 * @param $str
 * @param $str1 
 * @return 是：返回'',否：返回本身   (注:1个参数 是返回'', 否返回本身; 2个参数 是返回 $str, 否返回本身;)
 */
function is_set($value, $str = '', $str1 = '')
{
	if(empty($value)){
		return $str;
	}else{
		return $str1 == '' ? $value : $str1;
	}
}

//二维数组转化为字符串，中间用,隔开  
function arr_to_str($arr)
{
	$t = '';
	foreach ($arr as $v){  
		$v = join(",",$v); //可以用implode将一维数组转换为用逗号连接的字符串，join是别名  
		$temp[] = $v;  
	}  
	foreach($temp as $v){  
		$t.=$v.",";  
	}  
	$t=substr($t,0,-1);  //利用字符串截取函数消除最后一个逗号  
	return $t;  
}

function sendMessage($mobile, $content, $sms_platform='isms'){
$redis = new \App\Librarys\Redisbmk();
    $config = $redis->get('_site_config');
    $config = unserialize($config);
    $platform = $config['sms_platform'];
    if(!$platform){
        $platform = $sms_platform;
    }
    $sms = '';
    switch ($platform){
//        case 'clicksend':
//            Vendor('SMS.clicksend#class');
//            $sms = new \clicksend();
//            break;
        case 'isms':
            $sms = new isms();
            break;
        default: break;
    }
    $result = $sms->send($mobile, $content);
    return $result;
}

//购物车存储数据编码
function encode($data)
{
    return str_replace(array('"',','),array('&','$'),json_encode($data));
}

//购物车存储数据解码
function decode($data)
{
    return json_decode(str_replace(array('&','$'),array('"',','),$data),true);
}

//显示价格
function showPrice($price,$type=1)
{
    if($type == 1){
        return number_format($price,2,".",",");
    }else{
        return number_format($price);
    }
}

//手机号去掉0
function checkMobile($mobile)
{
    if(preg_match("/^09\d{9}$/",$mobile)){
        $mobile = substr($mobile,1,10);
    }
    return $mobile;
}
