<?php

namespace App\Models;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Cart extends Authenticatable
{
    use HasApiTokens, Notifiable;


    //购物车存储数据编码
    static public function encode($data)
    {
        return str_replace(array('"',','),array('&','$'),json_encode($data));
    }

    //购物车存储数据解码
    static public function decode($data)
    {
        return json_decode(str_replace(array('&','$'),array('"',','),$data),true);
    }

    /**
     * @brief 将商品或者货品加入购物车
     * @param $gid  商品或者货品ID值
     * @param $num  购买数量
     * @param $type 加入类型 goods商品; product:货品;
     */
    public function cartAdd($goods_id,$goods_num,$type)
    {

        //规格必填
        if($type == "goods")
        {
            if(DB::table('products')->where('goods_id', $goods_id)->first())
            {
                return array(
                    'error' => 'Please choose product specifications'
                );
            }
        }
        //更新购物车内容
        $cartInfo = $this->UpdateCartData($goods_id,$goods_num,$type);
        return $cartInfo;
    }

    /**
     * 获取新加入购物车的数据
     * @param $gid 商品或者货品ID
     * @param $num 数量
     * @param $type goods 或者 product
     * @param $user_id 用户id
     */
    public function UpdateCartData($gid,$num,$type)
    {
        $cartInfo = array();
        $gid = intval($gid);
        $num = intval($num);
        $user_id = Auth::id();
        if($type != 'goods')
        {
            $type = 'product';
        }

        //获取基本的商品数据
        $goodsRow = $this->getGoodInfo($gid,$type);
        if($goodsRow)
        {
            //查询数据库 获取购物车是否有user的信息
            $cartRow = DB::table('goods_car')->where('user_id', $user_id)->first();
            if($cartRow){
                $res  =  $this->decode($cartRow->content);
                //更新已有商品信息数据
                if(isset($res[$type][$gid])){
                    if($goodsRow->store_nums < $num)
                    {
                        return array(
                            'error' => 'The inventory is not enough for the supply'
                        );
                    }
                    if($num <= 0){
                        return array(
                            'error' => 'Purchase quantity must be greater than 0'
                        );
                    }
                    $res[$type][$gid] = $num;
                }else{
                    if($goodsRow->store_nums < $num)
                    {
                        return array(
                            'error' => 'The inventory is not enough for the supply'
                        );
                    }
                    if($num <= 0)
                    {
                        return array(
                            'error' => 'Purchase quantity must be greater than 0'
                        );
                    }
                    $res[$type][$gid] = $num;
                }
                $res = $this->encode($res);
                $dataArray = array('content' => $res,'create_time' => date("Y-m-d H:i:s"));
                $flag = DB::table('goods_car')->where('user_id', $user_id)->update($dataArray);
                return $flag;
            }else{
                $res[$type][$gid] = $num;
                $res = $this->encode($res);
                $dataArray = array('content' => $res,'user_id'=>$user_id,'create_time' => date("Y-m-d H:i:s"));
                $flag = DB::table('goods_car')->insert($dataArray);
                return $flag;
            }
        }else{
            return array(
                'error' => 'Unable to match the information of the product'
            );
        }
    }

    //根据 $gid 获取商品信息
    public function getGoodInfo($gid, $type = 'goods')
    {
        $dataArray = array();

        //商品方式
        if($type == 'goods')
        {
            $dataArray = DB::table('goods')
                ->where('id', $gid)
                ->where('is_del', 0)
                ->select('name', 'id as goods_id', 'img', 'sell_price', 'point', 'weight', 'store_nums', 'exp', 'goods_no', 'seller_id', 'is_shipping')
                ->first();
            if($dataArray)
            {
                $dataArray->id = $dataArray->goods_id;
            }
        }
        //货品方式
        else
        {
            $productRow = DB::table('products as pro')
                ->join('goods as go', 'pro.goods_id', '=', 'go.id')
                ->where('pro.id', $gid)->where('is_del', 0)
                ->select('pro.sell_price', 'pro.weight', 'pro.id as product_id', 'pro.spec_array', 'pro.goods_id', 'pro.store_nums', 'pro.products_no as goods_no', 'go.name', 'go.point', 'go.exp', 'go.img', 'go.seller_id', 'go.is_shipping')
                ->first();
            if($productRow)
            {
                $dataArray = $productRow;
            }
        }
        return $dataArray;
    }

    /**
     * @brief
     * @param  $cartValue 购物车数据
     * @return array : [goods]=>array( ['id']=>商品ID , ['data'] => array( [商品ID]=>array ([name]商品名称 , [img]图片地址 , [sell_price]价格, [count]购物车中此商品的数量 ,[type]类型goods,product , [goods_id]商品ID值 ) ) ) , [product]=>array( 同上 ) , [count]购物车商品和货品数量 , [sum]商品和货品总额 ;
     */
    public function cartFormat($cartValue)
    {
        $result = array('goods' => array('id' => array(), 'data' => array() ),'product' => array( 'id' => array() , 'data' => array()),'count' => 0,'sum' => 0);
        $goodsIdArray = array();
        $result['count'] = 0;
        $result['sum'] = 0;
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

            $productData    = DB::table('products')->whereIn('id', $productIdArray)->select('id', 'goods_id', 'sell_price')->get();
            foreach($productData as $proVal)
            {
                $result['product']['data'][$proVal->id] = array(
                    'id'         => $proVal->id,
                    'type'       => 'product',
                    'goods_id'   => $proVal->goods_id,
                    'count'      => $cartValue['product'][$proVal->id],
                    'sell_price' => $proVal->sell_price,
                );

                if(!in_array($proVal->goods_id,$goodsIdArray))
                {
                    $goodsIdArray[] = $proVal->goods_id;
                }

                //购物车中的种类数量累加
                $result['count'] += $cartValue['product'][$proVal->id];
            }
        }
        if($goodsIdArray)
        {
            $goodsArray = array();
            $goodsData  = DB::table('goods')->where('id', $goodsIdArray)->select('id', 'name', 'img', 'sell_price')->get();
            foreach($goodsData as $goodsVal)
            {
                $goodsArray[$goodsVal->id] = $goodsVal;
            }
            foreach($result['goods']['data'] as $key => $val)
            {
                if(isset($goodsArray[$val['goods_id']]))
                {
                    $result['goods']['data'][$key]['img']        = getImgDir($goodsArray[$val['goods_id']]->img,120,120);
                    $result['goods']['data'][$key]['name']       = $goodsArray[$val['goods_id']]->name;
                    $result['goods']['data'][$key]['sell_price'] = $goodsArray[$val['goods_id']]->sell_price;

                    //购物车中的金额累加
                    $result['sum']   += $goodsArray[$val['goods_id']]->sell_price * $val['count'];
                }
            }

            foreach($result['product']['data'] as $key => $val)
            {
                if(isset($goodsArray[$val['goods_id']]))
                {
                    $result['product']['data'][$key]['img']  = getImgDir($goodsArray[$val['goods_id']]->img,120,120);
                    $result['product']['data'][$key]['name'] = $goodsArray[$val['goods_id']]->name;

                    //购物车中的金额累加
                    $result['sum']   += $result['product']['data'][$key]['sell_price'] * $val['count'];
                }
            }
        }
        return $result;
    }

    public function removeCart($id,$type)
    {
        $cartInfo = DB::table('goods_car')->where('user_id', Auth::id())->first();
        $content = $this->decode($cartInfo->content);
        if($type != 'goods')
        {
            $type = 'product';
        }
        if(isset($content[$type][$id]))
        {
            unset($content[$type][$id]);
        }
        $dataArray = array('content' => $this->encode($content),'create_time' => date("Y-m-d H:i:s"));
        $flag = DB::table('goods_car')->where('user_id', Auth::id())->update($dataArray);
        return $flag;
    }

}

?>