<?php

namespace App\Htpp\Traits;

use App\Librarys\Redisbmk;

trait RedisFucntion
{

    public $redis;

    public function __construct()
    {
        $this->redis = new Redisbmk();
    }

    /**
     * CreateTime: 2018/7/25 9:41
     * Description: 用户最近浏览
     */
    public function setUserRecentlyViewed($user_id, $goods_id)
    {
        $view_count = $this->redis->listcount('_recently_viewed:user_id_' . $user_id);
        //如果已存在记录 ，先删除
        $list = $this->redis->lRange('_recently_viewed:user_id_' . $user_id, 0, $view_count - 1);
        $index = array_search($goods_id, $list);
        if ($index !== false) {
            $this->redis->lRem('_recently_viewed:user_id_' . $user_id, 1, $goods_id);
        }
        //再次统计记录个数
        $view_count = $this->redis->listcount('_recently_viewed:user_id_' . $user_id);
        if ($view_count >= 6) {
            //尾部出列
            $this->redis->rpoplist('_recently_viewed:user_id_' . $user_id);
        }
        //头入列
        $this->redis->addLlist('_recently_viewed:user_id_' . $user_id, $goods_id);
        return true;
    }

    /**
     * @param $goods_id
     * CreateTime: 2018/7/25 14:28
     * Description: 新增PV （商品页）
     */
    public function addGoodsPvByGoodsInfo($goods_id)
    {
        $res['pv'] = $this->redis->get('_goods_pv:good_id_' . $goods_id);
        if (!$res['pv']) {
            $this->redis->set('_goods_pv:good_id_' . $goods_id, $res['visit'] + 1);
            $res['pv'] = $this->redis->get('_goods_pv:good_id_' . $goods_id);
        } else {
            $this->redis->set('_goods_pv:good_id_' . $goods_id, $res['pv'] + 1);
        }

        return true;
    }

    /**
     * CreateTime: 2018/7/25 14:29
     * Description:\ //商家每日访问量（pv数 包括店铺页，商品页，商品列表页）
     */
    public function addSellerPv($seller_id)
    {
        $this->redis->hIncrBy('_seller_visi:seller_id_' . $seller_id, date('Y-m-d'), 1);
        return true;
    }

}