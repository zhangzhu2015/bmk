<?php
/**
 * isms.php
 * ==============================================
 * Copy right 2017 http://www.bigmk.ph/
 * ----------------------------------------------
 * This is not a free software, without any authorization is not allowed to use and spread.
 * ==============================================
 * @desc: isms短信发送接口
 * @brief 短信发送接口官网 http://www.bulksms.com.ph
 * @author: guoding
 * @date: 2017年11月9日
 */

class isms
{
	private $submitUrl  = "http://www.isms.com.my/isms_send.php";
	//http://www.isms.com.my/isms_send.php?un=isms&pwd=isms&dstno=60123456789&msg=Hello%20World&type=1&sendid=12345

	/**
	 * @brief 获取config用户配置
	 * @return array
	 */
	public function getConfig()
	{
		return array(
			'username' => 'bigmk666',
			'userkey'  => 'dsa43#43_32jsd',
		    'sendid'   => 'BIGMK',
		);
	}

	/**
	 * @brief 发送短信
	 * @param string $mobile
	 * @param string $content
	 * @return
	 */
	public function send($mobile, $content)
	{
		$config = self::getConfig();

		$mobile = '63'.$mobile;
		$post_data = array(
			'method' => 'http',
			'un'     => $config['username'],
			'pwd'    => $config['userkey'],
			'sendid' => $config['sendid'],
			'dstno'  => $mobile,			
			'msg'    => $content,
		    'type'   => 1,
		);

		$url    = $this->submitUrl;
		$string = '';
		foreach ($post_data as $k => $v)
		{
		   $string .="$k=".urlencode($v).'&';
		}

		$post_string = substr($string,0,-1);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_URL,$url);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post_string);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); //如果需要将结果直接返回到变量里，那加上这句。
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		$result = curl_exec($ch);
		return $this->response($result);
	}

	/**
	 * @brief 解析结果
	 * @param $result 发送结果
	 * @return string success or fail
	 */
	public function response($result)
	{
		if(strpos($result,'2000') !== false)
		{
			return 'Success';
		}
		else
		{
			return $this->getMessage(substr($result , 0 , 5));
		}
	}

	//返回消息提示
	public function getMessage($code)
	{
		$messageArray = array(
			"-1000" =>"UNKNOWN ERROR",
			"-1001"  =>"AUTHENTICATION FAILED",
			"-1002"  =>"ACCOUNT SUSPENDED / EXPIRED",
			"-1003"  =>"IP NOT ALLOWED",
			"-1004"  =>"INSUFFICIENT CREDITS",
			"-1005"  =>"INVALID SMS TYPE",
			"-1006"  =>"INVALID BODY LENGTH (1-900)",
			"-1007" =>"INVALID HEX BODY",
			"-1008" =>"MISSING PARAMETER",
		);
		return isset($messageArray[$code]) ? $messageArray[$code] : "Internal error.";
	}

	/**
	 * @brief 获取参数
	 */
	public function getParam()
	{
	    return array(
	        "username" => "用户名",
	        "userkey"  => "密码",
	        "usersign" => "短信签名",
	    );
	}

}

?>