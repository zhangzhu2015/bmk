<?php
namespace App\Http\Controllers\V1;
 
use App\Htpp\Traits\ApiResponse;
use App\Librarys\Redisbmk;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;//引入自带接数据库
use Illuminate\Support\Facades\DB;//引入DB
use Validator;
 
class PassportController extends Controller
{
    use ApiResponse;
    public $successStatus = 200;


    //手机号去掉0
    public function checkMobile($mobile)
    {
        if(preg_match("/^09\d{9}$/",$mobile)){
            $mobile = substr($mobile,1,10);
        }
        return $mobile;
    }

    /**
     * login api
     *
     * @return \Illuminate\Http\Response
     */
    public function login(Request $request){
        //
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string|max:32|min:6',
        ])->validate();
        $username = $request->username;
        $password = $request->password;
        if(!preg_match('|\S{6,32}|', $password))
        {
            return $this->error(400, 'Incorrect password(6-32 characters).');
        }
        if(Auth::attempt($request->all())){
            $user = Auth::user();
            $userRow = DB::table('user as u')->join('member as m', 'm.user_id', '=', 'u.id')->where('u.id', $user->id)->where('m.status', 1)->first();
            $this->userLoginCallback($userRow);
            $userRow->token =  $user->createToken('MyApp')->accessToken;
            return $this->success($userRow);
        }
        else{
            return $this->error(400, 'Incorrect username or password.');
        }
    }
 
    /**
     * Register api
     * 发送验证码
     * @return \Illuminate\Http\Response
     */
    public function send_mobile_code(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type'   => 'required|integer',
            'mobile' => 'required|string',
        ])->validate();
        $type = $request->type;
        $mobile = $request->mobile;
        if ($type !== 3){
            if($mobile === null || !preg_match("!^0?9[0-9]\d{8}$!", $mobile))
            {
                return $this->error(400, 'Incorrect phone number format');
            }
            $memberRow = DB::table('member')->where('mobile', $this->checkMobile($mobile))->first();
            if($type == 1) {
                if ($memberRow) {
                    return $this->error(400, 'The phone number has been registered');
                }
            }
        }
        $mobile_code = rand(10000,99999);
        //S('code'.$mobile,$mobile_code);
        $redis = new Redisbmk();
        $_mobileCode = $redis->get('_mobile_code:'.$mobile);
        if($_mobileCode){
            return $this->error(400, 'Message can be sent only once in 120 seconds, please kindly try again after 120 seconds!');
        }else{
            $redis->setex('_mobile_code:'.$mobile, 120, $mobile_code);
        }
        //工作环境
        $development = env('BMK_HOST');
        if ($development == 'https://www.bigmk.ph/' || $development == 'http://www.bigmk.ph/'){
            $content = "Your verification code is:".$mobile_code.", Please take care!";
            $result = sendMessage($mobile, $content, 'isms');
            if($result != 'Success'){
                return $this->error(400, $result);
            }
        }else {
            $result = 'test';
        }
        if($result == 'test')
        {
            //S('code'.$mobile,$mobile_code);
            $data['mobile_code'] = $mobile_code;
            return $this->success($data);
        }
        else{
            //$this->apiSuccess($result);//20170517由于安卓要在本地做对比，故做修改
            $data['mobile_code'] = $mobile_code;
            return $this->success($data);
        }
    }


    /***
     * 手机号注册 第一步
     */
    public function registerBymobile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mobile_code' => 'required|string',
            'mobile'      => 'required|string|unique:member,mobile',
        ])->validate();
        $mobile = $request->mobile;
        $mobile_code= $request->mobile_code;
        if($mobile === null || !preg_match("!^0?9[0-9]\d{8}$!", $mobile))
        {
            $this->error(400, 'Invalid phone number');
        }
        $redis = new Redisbmk();
        $_mobileCode = $redis->get('_mobile_code:'.$mobile);
        if(!$mobile_code || !$_mobileCode || $mobile_code !== $_mobileCode)
        {
            return $this->error(400, 'Please enter the correct captcha code');
        }
        $memberRow = DB::table('member')->where('mobile', $this->checkMobile($mobile))->first();
        if($memberRow)
        {
            return $this->error(400, 'The phone number has been registered');
        }
        return $this->success(array());
    }

    /***
     * 手机号注册 第二步
     */
    public function registerBymobile2(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'                 => 'required|email|unique:member,email',
            'username'              => 'required|string|max:20|min:2|unique:user,username',
            'password'              => 'required|string|max:32|min:6|confirmed',
            'password_confirmation' => 'required|string|max:32|min:6',
            'mobile'                => 'required|string|unique:member,mobile',
        ])->validate();
        $email      = $request->email;
        $username   = $request->username;
        $password   = $request->password;
        $repassword = $request->password_confirmation;
        $mobile = $request->mobile;
        //密码验证
        if(!preg_match('|\S{6,32}|', $password))
        {
            return $this->error(400, 'Incorrect password(6-32 characters).');
        }
        if($password != $repassword)
        {
            return $this->error(400, 'Inconsistent input password');
        }
        //邮箱验证
        if(!preg_match('/^\w+([-+.]\w+)*@\w+([-.]\w+)+$/i', $email))
        {
            return $this->error(400, 'Invalid email address');
        }
        $memberRow = DB::table('member')->where('email', $email)->first();
        if($memberRow)
        {
            return $this->error(400, 'E-mail already exists');
        }
        //用户名检查
        if(preg_match("!^[\w\x{4e00}-\x{9fa5}]{2,30}$!u", $username) == false)
        {
            return $this->error(400, 'Incorrect username(2-20 characters, can contain letters, numbers and underscores).');
        }
        else
        {
            $userRow = DB::table('user')->where('username', $username)->first();
            if($userRow)
            {
                return $this->error(400, 'Username already exists');
            }
        }
        //插入user表
        DB::beginTransaction();
        try{
            $userArray = array(
                'username' => $username,
                'password' => md5($password),
            );
            $user_id = DB::table('user')->insertGetId($userArray);
            if(!$user_id)
            {
                return $this->error(400, 'Failed');
            }
            $dataArray2 = array(
                'useruin' => '83000'.$user_id,
            );
            DB::table('user')->where('id', $user_id)->update($dataArray2);
            //插入member表
            $memberArray = array(
                'user_id' => $user_id,
                'time'    => date('Y-m-d H:i:s'),
                'status'  => 1,
                'mobile'  => $this->checkMobile($mobile),
                'email'   => $email,
            );
            DB::table('member')->insert($memberArray);
            DB::commit();
        }catch (\Exception $e){
            DB::rollback();//事务回滚
            return $this->error(400, 'Data abort');
        }

        //即时通讯用户注册
        $Uins = '83000'.$user_id;
        $tmp = date("YmdHis");                        //临时字符
        $user = array(
            'accid'=>$Uins,
            'name' =>$username,
            'props'=>'',
            'icon' =>'',
        );

        $userArray['id']       = $user_id;
        $userArray['email']    = $email;
        $userArray['useruin']  = $Uins;
        $userArray['head_ico'] = '';
        $userRow = DB::table('user as u')->join('member as m', 'm.user_id', '=', 'u.id')->where('u.id', $user_id)->where('m.status', 1)->first();
        $user = User::find($user_id);
        $userRow->token = $user->createToken('MyApp')->accessToken;
        return $this->success($userRow);
    }

    /***
     * 手机登录
     */
    public function login_mobile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mobile' => 'required|string',
            'mobile_code' => 'required|string',
        ])->validate();
        $mobile = $request->mobile;
        $mobile_code= $request->mobile_code;
        if($mobile === null || !preg_match("!^0?9[0-9]\d{8}$!",$mobile))
        {
            return $this->error(400,'Invalid phone number');
        }
        //$_mobileCode = S('code'.$mobile);
        $redis = new Redisbmk();
        $_mobileCode = $redis->get('_mobile_code:'.$mobile);
        if(!$mobile_code || !$_mobileCode || $mobile_code != $_mobileCode)
        {
            return $this->error(400,'Incorrect verification code');
        }
        $memberRow = DB::table('member')->where('mobile',$mobile)->first();
        if($memberRow)
        {
            //存在用户直接登录
            $userRow = DB::table('user as u')->join('member as m', 'm.user_id', '=', 'u.id')->where(function($query) use ($mobile){
                $query->where('u.username', $this->checkMobile($mobile))
                    ->orWhere('m.email', $this->checkMobile($mobile))
                    ->orWhere('m.mobile', $this->checkMobile($mobile));
            })->where('m.status',1)->first();
            if ($userRow){
                $this->userLoginCallback($userRow);
                $user = User::find($userRow->id);
                $userRow->token =  $user->createToken('MyApp')->accessToken;
                return $this->success($userRow);
            }
        }else {
            DB::beginTransaction();
            try{
                //插入user表
                $password = $mobile.rand(10,10000);
                $userArray = array(
                    'username' => $mobile,
                    'password' => md5($password),
                    'head_ico' => '',
                );
                $user_id = DB::table('user')->insertGetId($userArray);
                //插入member表
                $memberArray = array(
                    'user_id' => $user_id,
                    'time'    => date("Y-m-d H:i:s"),
                    'status'  => 1,
                    'mobile'   => $this->checkMobile($mobile),
                );
                DB::table('member')->insert($memberArray);
                //即时通讯用户注册
                $Uins = '83000'.$user_id;
                if($user_id){
                    DB::table('user')->where('id',$user_id)->update(['useruin'=>$Uins]);
                }
                DB::commit();
            }catch (\Exception $e){
                DB::rollback();//事务回滚
                return $this->error(400,'Data abort');
            }

            //用户注册统计
            $redis = new Redisbmk();
            $redis->hIncrBy('_sale_user_d', date('Y-m-d'), 1);
            $redis->hIncrBy('_sale_user_m', date('Y-m'), 1);

            $userRow = DB::table('user as u')->join('member as m', 'm.user_id', '=', 'u.id')->where(function($query) use ($mobile){
                $query->where('u.username', $this->checkMobile($mobile))
                    ->orWhere('m.email', $this->checkMobile($mobile))
                    ->orWhere('m.mobile', $this->checkMobile($mobile));
            })->where('m.status',1)->first();
            if ($userRow){
                $this->userLoginCallback($userRow);
                $user = User::find($userRow->id);
                $userRow->token =  $user->createToken('MyApp')->accessToken;
                return $this->success($userRow);
            }
        }
    }

    /**
     * @brief 用户登录
     * @param array $userRow 用户信息登录
     */
    public function userLoginCallback($userRow)
    {
        //更新最后一次登录时间
        $dataArray = array(
            'last_login' => date("Y-m-d H:i:s"),
        );
        DB::beginTransaction();
        try{
            DB::table('member')->where('user_id', $userRow->id)->update($dataArray);
            //根据经验值分会员组
            $memberRow = DB::table('member')->where('user_id', $userRow->id)->first();
            $groupRow = DB::table('user_group')
                ->where('minexp', '<', $memberRow->exp)
                ->where('maxexp', '>', $memberRow->exp)
                ->where('maxexp', '>', 0)
                ->where('minexp', '>', 0)
                ->first();
            if($groupRow)
            {
                $dataArr = array('group_id' => $groupRow['id']);
                DB::table('member')->where('user_id', $userRow->id)->update($dataArr);
            }
            DB::commit();
        }catch (\Exception $e){
            DB::rollback();//事务回滚
            return $this->error(400,'Data abort');
        }

    }


    /***
     * 第三方登录
     */
    public function oauth_login(Request $request)
    {
        $oauth_id = $request->oauth_id;
        if(!$oauth_id){
            return $this->error(400, 'Authorization failed');
        }

        //第三方用户信息
        $oauth_info = array(
            'oauth_user_id'    => $request->oauth_user_id,
            'oauth_user_name'  => $request->oauth_user_name,
            'oauth_user_email' => $request->oauth_user_email,
            'oauth_user_icon'  => $request->oauth_user_icon?$request->oauth_user_icon:'',
            'oauth_user_sex'   => $request->oauth_user_sex?$request->oauth_user_sex:'',
        );
        if($oauth_info['oauth_user_icon']){
            $oauth_info['oauth_user_icon'] = str_replace('#', '&', str_replace('@', '?', $oauth_info['oauth_user_icon']));
        }
        $oauth_user_row = DB::table('oauth_user')->where('oauth_id', $oauth_id)->where('oauth_user_id', $oauth_info['oauth_user_id'])->first();
        //如果oauth_user表已经存在数据
        if ($oauth_user_row){
            //清理oauth_user和user表不同步匹配的问题
            $user_row_tmp = DB::table('user')->where('id', $oauth_user_row->user_id)->find();
            //user表找不到数据
            if(!$user_row_tmp){
                DB::table('oauth_user')->where('oauth_id', $oauth_id)->where('oauth_user_id', $oauth_info['oauth_user_id'])->delete();
            }
        }

        //存在绑定账号oauth_user与user表同步正常,执行登录
        if(isset($user_row_tmp) && $user_row_tmp){
            if($user_row = DB::table('user')->where('username', $user_row_tmp->username)->where('password', $user_row_tmp->password)->first()){
                //即时通讯用户注册
                $Uins = '83000'.$user_row['id'];
                if(!$user_row->useruin || $Uins !== $user_row->useruin){
                    DB::table('user')->where('id', $user_row->id)->update(['useruin'=>$Uins]);
//                    $user_row['useruin'] = $Uins;
                }
                $this->userLoginCallback($user_row);
                $user = User::find($user_row->id);
                $user_row->token =  $user->createToken('MyApp')->accessToken;
                return $this->success($user_row);
            }
            return $this->error(400,'未知错误');
        }else { //没有绑定账号 执行绑定登录
            //1.fb邮箱已经注册用户自动绑定登录 2.fb邮箱未注册用户，自动注册绑定并登录
            $member_row = DB::table('member')->where('email', $oauth_info['oauth_user_email'])->first();
            if ($member_row && $oauth_info['oauth_user_email']){
                $user_row = DB::table('user')->where('id', $member_row->user_id)->first();
                if (!$user_row->head_ico) {
                    DB::table('user')->where('id', $user_row->id)->update(['head_ico' => $oauth_info['oauth_user_icon']]);
                }
                //插入关系表
                DB::beginTransaction();
                try{
                    $oauthUserData = array(
                        'oauth_user_id' => $oauth_info['oauth_user_id'],
                        'oauth_id'      => $oauth_id,
                        'user_id'       => $user_row->id,
                        'datetime'      => date("Y-m-d H:i:s"),
                    );
                    DB::table('oauth_user')->insert($oauthUserData);

                    //即时通讯用户注册
                    $Uins = '83000'.$user_row->id;
                    if(!$user_row->useruin || $Uins !== $user_row->useruin){
                        DB::table('user')->where('id', $user_row->id)->update(['useruin' => $Uins]);
                    }
                    DB::commit();
                }catch (\Exception $e){
                    DB::rollback();//事务回滚
                    return $this->error(400,'Data abort');
                }

            }

            if (!$user_row){
                $user_count = DB::table('user')->where('username', $oauth_info['oauth_user_name'])->count();
                //没有重复的用户名
                if($user_count == 0){
                    $username = $oauth_info['oauth_user_name'];
                }else{
                    //分配一个用户名
                    $username = $oauth_info['oauth_user_name'].time();
                }

                //插入user表
                $password = "bigmk123456";
                DB::beginTransaction();
                try{
                    $userArray = array(
                        'username' => $username,
                        'password' => md5($password),
                        'head_ico' => $oauth_info['oauth_user_icon'],
                    );
                    $user_id = DB::table('user')->insertGetId($userArray);

                    //插入member表
                    $memberArray = array(
                        'user_id' => $user_id,
                        'time'    => date("Y-m-d H:i:s"),
                        'status'  => 1,
                        'email'   => $oauth_info['oauth_user_email'],
                    );
                    DB::table('member')->insert($memberArray);
                    //插入关系表
                    $oauthUserData = array(
                        'oauth_user_id' => $oauth_info['oauth_user_id'],
                        'oauth_id'      => $oauth_id,
                        'user_id'       => $user_id,
                        'datetime'      => date("Y-m-d H:i:s"),
                    );
                    DB::table('oauth_user')->insert($oauthUserData);
                    $user_row = DB::table('user')->where('id', $user_id)->first();
                    //即时通讯用户注册
                    $Uins = '83000'.$user_row->id;
                    if($user_row->user_id){
                        DB::table('user')->where('id', $user_id)->update(['useruin' => $Uins]);
                    }
                    DB::commit();
                }catch(\Exception $e){
                    DB::rollback();//事务回滚
                    return $this->error(400, 'Data abort');
                }

                //用户注册统计
                $redis = new Redisbmk();
                $redis->hIncrBy('_sale_user_d', date('Y-m-d'), 1);
                $redis->hIncrBy('_sale_user_m', date('Y-m'), 1);
            }

            if($user_row){
                $this->userLoginCallback($user_row);
                $user = User::find($user_row->id);
                $user_row->token =  $user->createToken('MyApp')->accessToken;
                return $this->success($user_row);
            }
            return $this->error(400,'Unknown error');
        }
    }

    /***
     * 手机找回密码
     */
    public function findPwdByMobile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mobile' => 'required|string',
            'mobile_code' => 'required|string',
        ])->validate();
        $mobile = $request->mobile;
        $mobile_code= $request->mobile_code;
        if($mobile === null || !preg_match("!^0?9[0-9]\d{8}$!", $mobile))
        {
            return $this->error(400, 'Incorrect phone number format');
        }
        $redis = new Redisbmk();
        $_mobileCode = $redis->get('_mobile_code:'.$mobile);
        if(!$mobile_code || !$_mobileCode || $mobile_code != $_mobileCode)
        {
            return $this->error(400,'Incorrect verification code');
        }

        $memberRow = DB::table('member')->where('mobile', $this->checkMobile($mobile))->first();
        if(!$memberRow)
        {
            return $this->error(400,'Phone number is not registered');
        }
        return $this->success(array());
    }


    /***
     * 重置密码
     */
    public function reset_password(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'password'              => 'required|string|max:32|min:6|confirmed',
            'password_confirmation' => 'required|string|max:32|min:6',
            'mobile'                => 'required|string',
        ])->validate();
        $password   = $request->password;
        $mobile     = $request->mobile;
        //密码验证
        if(!preg_match('|\S{6,32}|', $password))
        {
            return $this->error(400, 'Incorrect password(6-32 characters).');
        }
        $memberRow = DB::table('member')->where('mobile', $this->checkMobile($mobile))->first();
        if(!$memberRow){
            return $this->error(400, 'Username does not exist');
        }
        $dataArray = array(
            'password' => md5($password),
        );
        $res = DB::table('user')->where('id', $memberRow->user_id)->update($dataArray);
        if($res){
            return $this->success(array('user_id' => $memberRow->user_id));
        }else{
            return $this->error(400, 'Failed');
        }
    }


    //邮箱找回密码
    public function find_password_email(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ])->validate();
        $email = $request->email;
        //邮箱验证
        if(!$email || !preg_match('/^\w+([-+.]\w+)*@\w+([-.]\w+)+$/i',$email))
        {
            return $this->error(400, '请填写正确的邮箱号码');
        }
        $memberRow = DB::table('member')->where('email', $email)->first();
        if(!$memberRow){
            return $this->error(400, '邮箱未注册');
        }
        $hash = md5( microtime(true) .mt_rand());

        $dataArray = array( 'hash' => $hash , 'user_id' => $memberRow['user_id'] , 'addtime' => time() );
        if(DB::table('find_password')->where('hash', $hash)->first() || DB::table('find_password')->insert($dataArray))
        {
            $url     = env('BMK_HOST')."/simple/restore_password/hash/".$hash."/user_id/".$memberRow['user_id'];
            $templateString = "Hello, you have just applied for a Bigmk.ph retrieve the password operation, click the following link for password reset: <a href='{url}'>{url}</a>. <br />if you can not click, you can copy it into the address bar to open.";
            $content = strtr($templateString,array("{url}" => $url));

//            $result = send_email($email, "Retrieve your password", $content);
//            if($result===false)
//            {
//                return $this->error(400, '邮件发送失败，请联系管理员');
//            }
        }
        else
        {
            return $this->error(400, '刷新再试');
        }
        $this->success(['email' => $email]);
    }
    /**
     * details api
     *
     * @return \Illuminate\Http\Response
     */
    public function getDetails()
    {
        $user = Auth::user();
        return response()->json(['success' => $user], $this->successStatus);
    }
}
