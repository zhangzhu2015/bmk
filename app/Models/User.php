<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Laravel\Passport\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $table = 'user';
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'email', 'password',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token',
    ];

    /***
     * @param $cartValue
     * @return mixed
     * author: zhangzhu
     * create_time: 2018/7/18 0018
     * description: 用户购物车数据
     */
    public function getMyCart($cartValue){
        $result = array('goods' => array('id' => array(), 'data' => array() ),'product' => array( 'id' => array() , 'data' => array()),'count' => 0,'sum' => 0);
        $goodsIdArray = array();
        if(isset($cartValue['goods']) && $cartValue['goods'])
        {
            $goodsIdArray = array_keys($cartValue['goods']);
            $result['goods']['id'] = $goodsIdArray;
            foreach($goodsIdArray as $gid)
            {
                $result['goods']['data'][$gid] = array(
                    'id'       => $gid,
                    'type'     => 'goods',
                    'goods_id' => $gid,
                    'count'    => $cartValue['goods'][$gid],
                );

                //购物车中的种类数量累加
                $result['count'] += $cartValue['goods'][$gid];
            }
        }
        if(isset($cartValue['product']) && $cartValue['product'])
        {
            $productIdArray          = array_keys($cartValue['product']);
            $result['product']['id'] = $productIdArray;

            $productData    = DB::table('products')->whereIn('id', $productIdArray)->select('id','goods_id','sell_price')->get()->map(function ($value) {
                return (array)$value;
            })->toArray();

            foreach($productData as $proVal)
            {
                $result['product']['data'][$proVal['id']] = array(
                    'id'         => $proVal['id'],
                    'type'       => 'product',
                    'goods_id'   => $proVal['goods_id'],
                    'count'      => $cartValue['product'][$proVal['id']],
                    'sell_price' => $proVal['sell_price'],
                );

                if(!in_array($proVal['goods_id'],$goodsIdArray))
                {
                    $goodsIdArray[] = $proVal['goods_id'];
                }
                //购物车中的种类数量累加
                $result['count'] += $cartValue['product'][$proVal['id']];
            }
        }

        if($goodsIdArray)
        {
            $goodsArray = array();
            $goodsData    = DB::table('goods')->whereIn('id', $goodsIdArray)->select('id', 'name', 'sell_price', 'img')->get()->map(function ($value) {
                 return (array)$value;
            })->toArray();
            foreach($goodsData as $goodsVal)
            {
                $goodsArray[$goodsVal['id']] = $goodsVal;
            }

            foreach($result['goods']['data'] as $key => $val)
            {
                if(isset($goodsArray[$val['goods_id']]))
                {
                    $result['goods']['data'][$key]['img']        = getImgDir($goodsArray[$val['goods_id']]['img'],120,120);
                    $result['goods']['data'][$key]['name']       = $goodsArray[$val['goods_id']]['name'];
                    $result['goods']['data'][$key]['sell_price'] = $goodsArray[$val['goods_id']]['sell_price'];

                    //购物车中的金额累加
                    $result['sum']   += $goodsArray[$val['goods_id']]['sell_price'] * $val['count'];
                }
            }

            foreach($result['product']['data'] as $key => $val)
            {
                if(isset($goodsArray[$val['goods_id']]))
                {
                    $result['product']['data'][$key]['img']  = getImgDir($goodsArray[$val['goods_id']]['img'],120,120);
                    $result['product']['data'][$key]['name'] = $goodsArray[$val['goods_id']]['name'];

                    //购物车中的金额累加
                    $result['sum']   += $result['product']['data'][$key]['sell_price'] * $val['count'];
                }
            }
        }
        return $result;
    }
}

?>