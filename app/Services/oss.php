<?php
namespace App\Services;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use JohnLui\AliyunOSS;

class OSS {

    private $city = '硅谷';
    // 经典网络 or VPC
    private $networkType = '经典网络';

    private $AccessKeyId = '';
    private $AccessKeySecret = '';
    private $ossClient;


    public function __construct($isInternal = false)
    {
        $this->AccessKeyId = config('alioss.AccessKeyId');
        $this->AccessKeySecret = config('alioss.AccessKeySecret');
        $this->ossClient = AliyunOSS::boot(
            $this->city,
            $this->networkType,
            $isInternal,
            $this->AccessKeyId,
            $this->AccessKeySecret
        );
        $this->limitupload();
    }

    /** 根据ip限制上传 一个ip每天只能上传200次 达到200次发生邮件提醒管理员
     *
     */
    public function limitupload(){
        $ip = request()->getClientIp();
        $ipuploadRow = DB::table('ipupload')->whereRaw('ip = "'.$ip.'"')->first();
        $data['ip'] = $ip;
        if (!$ipuploadRow){ //不存在记录ip  直接添加
            $data['times'] = 1;
            $data['ctimes'] = 1;
            $data['addtime'] = Carbon::now();
            $rs = DB::table('ipupload')->insert($data);
            return $rs;
        }elseif ($ipuploadRow && $ipuploadRow->times >= 1000){ //大于500次 发送邮件提醒  并且终止上传
            $content = $ip."上传受到限制";

            Mail::raw($content,function($message)use ($content) {

                $to='826877189@qq.com';

                $message->to($to)->subject($content);
            });
            throw new \InvalidArgumentException($content);
        }else { //次数计时超过24小时 重新计算
            $data['times'] = $ipuploadRow->times + 1;
            $data['ctimes'] = $ipuploadRow->ctimes + 1;
            if (time()- strtotime($ipuploadRow->addtime) >= 60*60*24){
                $data['addtime'] =  Carbon::now();
                $data['times'] = 1;
            }
            $rs = DB::table('ipupload')->whereRaw('ip = "'.$ip.'"')->update($data);
            return $rs;
        }
    }

    /**
     * 使用外网上传文件
     * @param  string 上传之后的 OSS object 名称
     * @param  string 上传文件路径
     * @return boolean 上传是否成功
     */
    public static function publicUpload($ossKey, $filePath, $options = [])
    {
        $oss = new OSS();
        $oss->ossClient->setBucket(config('alioss.BucketName'));
        return $oss->ossClient->uploadFile($ossKey, $filePath, $options);
    }
    /**
     * 使用外网直接上传变量内容
     * @param  string 上传之后的 OSS object 名称
     * @param  string 上传的变量
     * @return boolean 上传是否成功
     */
    public static function publicUploadContent($ossKey, $content, $options = [])
    {
        $oss = new OSS();
        $oss->ossClient->setBucket(config('alioss.BucketName'));
        $dir = 'upload/'.date('Y/m/d').'/';
        $name = $ossKey;
        $ossKey = $dir.$name;//拼接的参数
        $oss->ossClient->uploadContent($ossKey, $content, $options);
        return $ossKey;
    }



}