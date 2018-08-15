<?php

namespace App\Librarys;

use Illuminate\Support\Facades\Redis;


/**
 * Redisbmk.php
 * ==============================================
 * Copy right 2017 http://www.bigmk.ph/
 * ----------------------------------------------
 * This is not a free software, without any authorization is not allowed to use and spread.
 * ==============================================
 * @author: guoding
 * @date: 2017年4月22日
 */
class Redisbmk{
    
    private $_redis = null;

    public function __construct(){
        $this->_redis = new Redis();
    }

    /*
     * 获取redis资源对象
     *
     */
    public function getHandel(){
        return $this->_redis;
    }
    /*********************Key操作命令************************/
    /*
     *  删除Key
     */
    public function keydel($key){
        return Redis::del($key);
    }
    
    /*********************队列操作命令************************/    
    /*
     * 队列尾追加
     *
     *
     */
    public function addRlist($key,$value){
        return Redis::rPush($key,$value);
        
    }
    
    
    /*
     * 队列头追加
     *
     *
     */
    public function addLlist($key,$value){
        return Redis::lPush($key,$value);
        
    }
    
    
    /*
     * 头出队列
     *
     */
    public function lpoplist($key){
        return Redis::lPop($key);
        
    }
    
    /*
     * 头出队列 阻塞式列表的弹出
     *
     */
    public function blpoplist($key,$timeout){
        return Redis::blPop($key,$timeout);
    }
    
    /*
     * 尾出队列
     *
     */
    public function rpoplist($key){
        return Redis::rPop($key);
        
    }
    
    /*
     * 尾出队列 阻塞式列表的弹出
     *
     */
    public function brpoplist($key,$timeout){
        return Redis::brPop($key,$timeout);
        
    }
    
    /*
     * 查看队列
     *
     */
    public function showlist($key){
        return Redis::lRange($key, 0, -1);
        
    }
    
    
    /**
     * 队列数量
     *
     */
    public function listcount($key){
        return Redis::lLen($key);
        
    }
    
    /*
     * 清空队列
     *
     */
    public function clearlist($key){
        return Redis::delete($key);
        
    }
    
    /**
     * 返回队列指定区间的元素
     * @param unknown $key
     * @param unknown $start
     * @param unknown $end
     */
    public function lRange($key,$start,$end)
    {
        return Redis::lrange($key,$start,$end);
    }
     
    /**
     * 返回队列中指定索引的元素
     * @param unknown $key
     * @param unknown $index
     */
    public function lIndex($key,$index)
    {
        return Redis::lIndex($key,$index);
    }
     
    /**
     * 设定队列中指定index的值。
     * @param unknown $key
     * @param unknown $index
     * @param unknown $value
     */
    public function lSet($key,$index,$value)
    {
        return Redis::lSet($key,$index,$value);
    }
     
    /**
     * 删除值为vaule的count个元素
     * PHP-REDIS扩展的数据顺序与命令的顺序不太一样，不知道是不是bug
     * count>0 从尾部开始
     *  >0　从头部开始
     *  =0　删除全部
     * @param unknown $key
     * @param unknown $count
     * @param unknown $value
     */
    public function lRem($key,$count,$value)
    {
        return Redis::lRem($key,$value,$count);
    }
     
    /**
     * 删除并返回队列中的头元素。
     * @param unknown $key
     */
    public function lPop($key)
    {
        return Redis::lPop($key);
    }
     
    /**
     * 删除并返回队列中的尾元素
     * @param unknown $key
     */
    public function rPop($key)
    {
        return Redis::rPop($key);
    }
    
    /*************redis字符串操作命令*****************/
    /*
     * set key
     *
     */
    public function set($key,$value){
        return Redis::set($key,$value);
        
    }
    
    /*
     * get key
     *
     */
    public function get($key){
        return Redis::get($key);
        
    }
    
    /**
     * 设置一个有过期时间的key
     * @param unknown $key
     * @param unknown $expire
     * @param unknown $value
     */
    public function setex($key,$expire,$value)
    {
        return Redis::setex($key,$expire,$value);
    }
    
    /**
     * 设置一个key,如果key存在,不做任何操作.
     * @param unknown $key
     * @param unknown $value
     */
    public function setnx($key,$value)
    {
        return Redis::setnx($key,$value);
    }
    
    
    /*****************hash表操作函数*******************/
      
    /**
     * 得到hash表中一个字段的值
     * @param string $key 缓存key
     * @param string  $field 字段
     * @return string|false
     */
    public function hGet($key,$field)
    {
        return Redis::hGet($key,$field);
    }
     
    /**
     * 为hash表设定一个字段的值
     * @param string $key 缓存key
     * @param string  $field 字段
     * @param string $value 值。
     * @return bool
     */
    public function hSet($key,$field,$value)
    {
        return Redis::hSet($key,$field,$value);
    }
     
    /**
     * 判断hash表中，指定field是不是存在
     * @param string $key 缓存key
     * @param string  $field 字段
     * @return bool
     */
    public function hExists($key,$field)
    {
        return Redis::hExists($key,$field);
    }
     
    /**
     * 删除hash表中指定字段 ,支持批量删除
     * @param string $key 缓存key
     * @param string  $field 字段
     * @return int
     */
    public function hdel($key,$field)
    {
        $fieldArr=explode(',',$field);
        $delNum=0;
    
        foreach($fieldArr as $row)
        {
            $row=trim($row);
            $delNum+=Redis::hDel($key,$row);
        }
    
        return $delNum;
    }
     
    /**
     * 返回hash表元素个数
     * @param string $key 缓存key
     * @return int|bool
     */
    public function hLen($key)
    {
        return Redis::hLen($key);
    }
     
    /**
     * 为hash表设定一个字段的值,如果字段存在，返回false
     * @param string $key 缓存key
     * @param string  $field 字段
     * @param string $value 值。
     * @return bool
     */
    public function hSetNx($key,$field,$value)
    {
        return Redis::hSetNx($key,$field,$value);
    }
     
    /**
     * 为hash表多个字段设定值。
     * @param string $key
     * @param array $value
     * @return array|bool
     */
    public function hMset($key,$value)
    {
        if(!is_array($value))
            return false;
        return Redis::hMset($key,$value);
    }
     
    /**
     * 为hash表多个字段设定值。
     * @param string $key
     * @param array|string $value string以','号分隔字段
     * @return array|bool
     */
    public function hMget($key,$field)
    {
        if(!is_array($field))
            $field=explode(',', $field);
        return Redis::hMget($key,$field);
    }
     
    /**
     * 为hash表设这累加，可以负数
     * @param string $key
     * @param int $field
     * @param string $value
     * @return bool
     */
    public function hIncrBy($key,$field,$value)
    {
        $value=intval($value);
        return Redis::hIncrBy($key,$field,$value);
    }
    
    public function hIncrByFloat($key,$field,$value)
    {
        return Redis::hIncrByFloat($key,$field,$value);
    }
     
    /**
     * 返回所有hash表的所有字段
     * @param string $key
     * @return array|bool
     */
    public function hKeys($key)
    {
        return Redis::hKeys($key);
    }
     
    /**
     * 返回所有hash表的字段值，为一个索引数组
     * @param string $key
     * @return array|bool
     */
    public function hVals($key)
    {
        return Redis::hVals($key);
    }
     
    /**
     * 返回所有hash表的字段值，为一个关联数组
     * @param string $key
     * @return array|bool
     */
    public function hGetAll($key)
    {
        return Redis::hGetAll($key);
    }
     
    /*********************有序集合操作*********************/
      
    /**
     * 给当前集合添加一个元素
     * 如果value已经存在，会更新order的值。
     * @param string $key
     * @param string $order 序号
     * @param string $value 值
     * @return bool
     */
    public function zAdd($key,$order,$value)
    {
        return Redis::zAdd($key,$order,$value);
    }
     
    /**
     * 给$value成员的order值，增加$num,可以为负数
     * @param string $key
     * @param string $num 序号
     * @param string $value 值
     * @return 返回新的order
     */
    public function zinCry($key,$num,$value)
    {
        return Redis::zinCry($key,$num,$value);
    }
     
    /**
     * 删除值为value的元素
     * @param string $key
     * @param stirng $value
     * @return bool
     */
    public function zRem($key,$value)
    {
        return Redis::zRem($key,$value);
    }
     
    /**
     * 集合以order递增排列后，0表示第一个元素，-1表示最后一个元素
     * @param string $key
     * @param int $start
     * @param int $end
     * @return array|bool
     */
    public function zRange($key,$start,$end)
    {
        return Redis::zRange($key,$start,$end);
    }
     
    /**
     * 集合以order递减排列后，0表示第一个元素，-1表示最后一个元素
     * @param string $key
     * @param int $start
     * @param int $end
     * @return array|bool
     */
    public function zRevRange($key,$start,$end)
    {
        return Redis::zRevRange($key,$start,$end);
    }
     
    /**
     * 集合以order递增排列后，返回指定order之间的元素。
     * min和max可以是-inf和+inf　表示最大值，最小值
     * @param string $key
     * @param int $start
     * @param int $end
     * @package array $option 参数
     *     withscores=>true，表示数组下标为Order值，默认返回索引数组
     *     limit=>array(0,1) 表示从0开始，取一条记录。
     * @return array|bool
     */
    public function zRangeByScore($key,$start='-inf',$end="+inf",$option=array())
    {
        return Redis::zRangeByScore($key,$start,$end,$option);
    }
     
    /**
     * 集合以order递减排列后，返回指定order之间的元素。
     * min和max可以是-inf和+inf　表示最大值，最小值
     * @param string $key
     * @param int $start
     * @param int $end
     * @package array $option 参数
     *     withscores=>true，表示数组下标为Order值，默认返回索引数组
     *     limit=>array(0,1) 表示从0开始，取一条记录。
     * @return array|bool
     */
    public function zRevRangeByScore($key,$start='-inf',$end="+inf",$option=array())
    {
        return Redis::zRevRangeByScore($key,$start,$end,$option);
    }
     
    /**
     * 返回order值在start end之间的数量
     * @param unknown $key
     * @param unknown $start
     * @param unknown $end
     */
    public function zCount($key,$start,$end)
    {
        return Redis::zCount($key,$start,$end);
    }
     
    /**
     * 返回值为value的order值
     * @param unknown $key
     * @param unknown $value
     */
    public function zScore($key,$value)
    {
        return Redis::zScore($key,$value);
    }
     
    /**
     * 返回集合以score递增加排序后，指定成员的排序号，从0开始。
     * @param unknown $key
     * @param unknown $value
     */
    public function zRank($key,$value)
    {
        return Redis::zRank($key,$value);
    }
     
    /**
     * 返回集合以score递增加排序后，指定成员的排序号，从0开始。
     * @param unknown $key
     * @param unknown $value
     */
    public function zRevRank($key,$value)
    {
        return Redis::zRevRank($key,$value);
    }
     
    /**
     * 删除集合中，score值在start end之间的元素　包括start end
     * min和max可以是-inf和+inf　表示最大值，最小值
     * @param unknown $key
     * @param unknown $start
     * @param unknown $end
     * @return 删除成员的数量。
     */
    public function zRemRangeByScore($key,$start,$end)
    {
        return Redis::zRemRangeByScore($key,$start,$end);
    }
     
    /**
     * 返回集合元素个数。
     * @param unknown $key
     */
    public function zCard($key)
    {
        return Redis::zCard($key);
    }
    
    
    /*********************无序集合操作*********************/
    /**
     * 给当前集合添加一个元素
     * 如果value已经存在，会更新order的值。
     * @param string $key
     * @param string $value 值
     * @return bool
     */
    public function sAdd($key,$value)
    {
        return Redis::sAdd($key,$value);
    }
    
    
    public function sInterStore($key,$key2,$key3){
        return Redis::sInterStore($key,$key2,$key3);
    }
    
    public function sort($set,$sort){
        return Redis::sort($set,$sort);
    }
    /*
     * mset key
     *
     */
    public function mset($key,$value){
        return Redis::mset($key,$value);
        
    }
    
    /*
     * get key
     *
     */
    public function mget($key){
        return Redis::mget($key);
        
    }
    
    //自增
    public function incr($key) {
        return Redis::incr($key);
    }
    
    //key过期设置
    public function expire($key,$expire) {
        return Redis::expire($key,$expire);
    }
    
    public function keys($key) {
        return Redis::keys($key."*");
    }
}