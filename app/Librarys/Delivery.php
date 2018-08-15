<?php
namespace App\Librarys;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * @copyright (c) 2011 aircheng.com
 * @file article.php
 * @brief 订单中配送方式的计算
 * @author relay
 * @date 2011-02-24
 * @version 0.6
 */
class Delivery
{
	//用户ID
	public static $user_id = 0;

	//首重重量
	private static $firstWeight  = 0;

	//次重重量
	private static $secondWeight = 0;

	/**
	 * 根据重量计算给定价格
	 * @param $weight float 总重量
	 * @param $firstFee float 首重费用
	 * @param $second float 次重费用
	 */
	private static function getFeeByWeight($weight,$firstFee,$secondFee)
	{
        //当商品重量为0时免运费
        if ($weight == 0){
            return 0;
        }
		//当商品重量小于或等于首重的时候
		if($weight <= self::$firstWeight)
		{
			return $firstFee;
		}

		//当商品重量大于首重时，根据次重进行累加计算
		$num = ceil(($weight - self::$firstWeight)/self::$secondWeight);
		return $firstFee + $secondFee * $num;
	}

	/**
	 * @brief 配送方式计算管理模块
	 * @param $province    int 省份的ID
	 * @param $delivery_id int 配送方式ID
	 * @param $goods_id    array 商品ID
	 * @param $product_id  array 货品ID
	 * @param $num         array 商品数量
     * @return array(
     *  id => 配送方式ID,
     *  name => 配送方式NAME,
     *  description => 配送方式描述,
     *	if_delivery => 0:支持配送;1:不支持配送,
     *  price => 实际运费总额,
     *  protect_price => 商品保价总额,
     *  org_price => 原始运费总额,
     *	seller_price => array(商家ID => 实际运费),
     *	seller_protect_price => array(商家ID => 商品保价),
     *  seller_org_price => array(商家ID => 原始运费),
     *
     *  freight  统一一口价运费
     */
	public function getDelivery($province,$delivery_id,$goods_id,$product_id = 0,$num = 1,$user_id=0)
	{
        //获取默认的配送方式信息
        $deliveryDefaultRow = DB::table('delivery')
            ->where([
                ['is_delete', '=', 0],
                ['status', '=', 1],
                ['id', '=', $delivery_id],
            ])->first();

		if(!$deliveryDefaultRow)
		{
		    throw new \InvalidArgumentException('Delivery does not exist!');
		}

		//最终返回结果
		$result = array(
			'id'            => $deliveryDefaultRow->id,
			'name'          => $deliveryDefaultRow->name,
			'description'   => $deliveryDefaultRow->description,
			'if_delivery'   => 0,
			'org_price'     => 0,
			'price'         => 0,
			'protect_price' => 0,
            'seller_org_price' => [],
            'seller_price' => [],
            'shippingcost' => [],
		);
		
		//读取全部商品,array('goodsSum' => 商品总价,'weight' => 商品总重量)
		$sellerGoods = array();
		$goods_id    = is_array($goods_id)  ? $goods_id   : array($goods_id);
		$product_id  = is_array($product_id)? $product_id : array($product_id);
		$num         = is_array($num)       ? $num        : array($num);
		$goodsArray  = array();
		$productArray= array();
		$is_shipping = array();
        $freight     = array();//商品一口价信息
        $template    = array();//适用于运费模板的商品信息
        $templatePrice     = array();//运费模板结果（运费信息）
		foreach($goods_id as $key => $gid)
		{
			$pid      = $product_id[$key];
			$gnum     = $num[$key];
            if($pid > 0)
			{
                $gid = $pid;
				$productArray[$pid] = $gnum;
                //取货品数据
                $goodsRow = DB::table('goods as go')
                    ->join('products as pro', 'pro.goods_id', '=', 'go.id')
                    ->where([
                        ['pro.id', '=', $pid],
                        ['go.is_del', '=', 0],
                    ])
                    ->select('pro.sell_price', 'pro.weight', 'pro.id as product_id', 'pro.spec_array', 'pro.goods_id', 'pro.store_nums', 'pro.products_no as goods_no',
                        'go.name', 'go.point', 'go.exp', 'go.img', 'go.seller_id', 'go.is_shipping', 'go.freight', 'go.template_id', 'pro.package_size', 'go.weight')
                    ->first();
                if(!$goodsRow)
                {
                    throw new \InvalidArgumentException("Product information【goods id ".$gid."】 does not exist");
                }
                $goodsRow = collect($goodsRow)->toArray();
                $weight = $goodsRow['weight'];

                //统一一口价运费
                if ($goodsRow['freight']){
                    $goodsRow['weight'] = 0;
                    $freight[$goodsRow['seller_id']][$gid] = $goodsRow['freight'];
                }
			}
			else
			{
				$goodsArray[$gid] = $gnum;

                //取商品数据
                $goodsRow = DB::table('goods as go')
                    ->where([
                        ['go.id', '=', $gid],
                        ['go.is_del', '=', 0],
                    ])
                    ->select('go.name', 'go.id as goods_id', 'go.img', 'go.sell_price', 'go.point', 'go.weight', 'go.store_nums', 'go.exp', 'go.goods_no',DB::raw('0 as product_id'),
                        'go.seller_id', 'go.is_shipping', 'go.freight', 'go.template_id', 'go.package_size')
                    ->first();

                if(!$goodsRow)
                {
                    throw new \InvalidArgumentException("Product information【product id ".$gid."】 does not exist");
                }

                $goodsRow = collect($goodsRow)->toArray();
                $weight = $goodsRow['weight'];
                //统一一口价运费
                if ($goodsRow['freight']){
                    $goodsRow['weight'] = 0;
                    $freight[$goodsRow['seller_id']][$gid] = $goodsRow['freight'];
                }

			}

            //运费模板
            if ($goodsRow['template_id']){
                $goodsRow['weight'] = 0;
                $shipping_template = DB::table('shipping_template')
                    ->where('id', '=', $goodsRow['template_id'])
                    ->first();
                $shipping_template = collect($shipping_template)->toArray();
                if ($shipping_template['free_shipping'] == 1){//不免运费
                    $template[$goodsRow['seller_id']][$gid] = array('template_id'=>$goodsRow['template_id'],'sum'=>$gnum,'weight'=>$weight,'package_size'=>$goodsRow['package_size']);
                }
            }

			if(!isset($sellerGoods[$goodsRow['seller_id']]))
			{
				$sellerGoods[$goodsRow['seller_id']] = array('goodsSum' => 0,'weight' => 0);
			}
			$sellerGoods[$goodsRow['seller_id']]['weight']  += $goodsRow['weight']     * $gnum;
			$sellerGoods[$goodsRow['seller_id']]['goodsSum']+= $goodsRow['sell_price'] * $gnum;
			$sellerGoods[$goodsRow['seller_id']]['goods'][$gid]['weight'] = $goodsRow['weight']* $gnum;
			$sellerGoods[$goodsRow['seller_id']]['goods'][$gid]['goodsSum'] = $goodsRow['sell_price']* $gnum;
		}

		//获取促销规则是否免运费
		$countSumObj    = new CountSum($user_id);
		$userModel       = new User();
		$countSumResult = $countSumObj->goodsCount($userModel->getMyCart(array("goods" => $goodsArray, "product" => $productArray)));

		//根据商家不同计算运费
        if (!$template && !$freight) //按照旧规则计算
        {
            foreach ($sellerGoods as $seller_id => $datas) {
                //判断商家是否设置全场免运费
                //获取商家信息
                $sellerrow = DB::table('seller')->where('id', $seller_id)->first();
                if ($sellerrow->is_shipping == 1) {
                    $result['shippingcost'][$seller_id] = 0;
                    $deliveryRow['price'] = 0;
                    continue;
                }

                $weight = $datas['weight'];//计算运费
                $goodsSum = $datas['goodsSum'];//计算保价

                //使用商家配置的物流运费
                $seller_id = intval($seller_id);
                $deliverySellerRow = DB::table('delivery_extend')
                    ->where([
                        ['delivery_id', '=', $delivery_id],
                        ['seller_id', '=', $seller_id],
                    ])
                    ->first();
                $deliverySellerRow = collect($deliverySellerRow)->toArray();
                $deliveryRow = $deliverySellerRow ? $deliverySellerRow : $deliveryDefaultRow;

                //设置首重和次重
                self::$firstWeight = $deliveryRow['first_weight'];
                self::$secondWeight = $deliveryRow['second_weight'];
                $deliveryRow['if_delivery'] = '0';

                //当配送方式是统一配置的时候，不进行区分地区价格
                if ($deliveryRow['price_type'] == 0) {
                    $deliveryRow['price'] = self::getFeeByWeight($weight, $deliveryRow['first_price'], $deliveryRow['second_price']);
                } //当配送方式为指定区域和价格的时候
                else {
                    $matchKey = '';
                    $flag = false;

                    //每项都是以';'隔开的省份ID
                    $area_groupid = unserialize($deliveryRow['area_groupid']);
                    if ($area_groupid) {
                        foreach ($area_groupid as $key => $item) {
                            //匹配到了特殊的省份运费价格
                            if (strpos($item, ';' . $province . ';') !== false) {
                                $matchKey = $key;
                                $flag = true;
                                break;
                            }
                        }
                    }

                    //匹配到了特殊的省份运费价格
                    if ($flag) {
                        //获取当前省份特殊的运费价格
                        $firstprice = unserialize($deliveryRow['firstprice']);
                        $secondprice = unserialize($deliveryRow['secondprice']);

                        $deliveryRow['price'] = self::getFeeByWeight($weight, $firstprice[$matchKey], $secondprice[$matchKey]);
                    } else {
                        //判断是否设置默认费用了
                        if ($deliveryRow['open_default'] == 1) {
                            $deliveryRow['price'] = self::getFeeByWeight($weight, $deliveryRow['first_price'], $deliveryRow['second_price']);
                        } else {
                            $deliveryRow['price'] = '0';
                            $deliveryRow['if_delivery'] = '1';
                        }
                    }
                }

                $deliveryRow['org_price'] = $deliveryRow['price'];
                $result['shippingcost'][$seller_id] = $deliveryRow['price'];
                //促销规则满足免运费
                if (isset($countSumResult['freeFreight']) && in_array($seller_id, $countSumResult['freeFreight'])) {
                    $deliveryRow['price'] = 0;
                    $result['shippingcost'][$seller_id] = 0;//2016-10-19   cart2  产品列表单独显示运费
                }


                //计算保价
                if ($deliveryRow['is_save_price'] == 1) {
                    $tempProtectPrice = $goodsSum * ($deliveryRow['save_rate'] * 0.01);
                    $deliveryRow['protect_price'] = ($tempProtectPrice <= $deliveryRow['low_price']) ? $deliveryRow['low_price'] : $tempProtectPrice;
                } else {
                    $deliveryRow['protect_price'] = 0;
                }

                //无法送达
                if ($deliveryRow['if_delivery'] == 1) {
                    $deliveryRow['id'] = $deliveryDefaultRow['id'];
                    $deliveryRow['name'] = $deliveryDefaultRow['name'];
                    $deliveryRow['description'] = $deliveryDefaultRow['description'];
                    $result['if_deli'] = 1;
                } else {
                    $result['if_deli'] = 0;
                }

                //更新最终数据
                $result['org_price'] += $deliveryRow['org_price'];
                $result['price'] += $deliveryRow['price'];
                $result['protect_price'] += $deliveryRow['protect_price'];

                $result['seller_org_price'][$seller_id] = $deliveryRow['org_price'];
                $result['seller_price'][$seller_id] = $deliveryRow['price'];
                $result['seller_protect_price'][$seller_id] = $deliveryRow['protect_price'];
//    		}
            }
        }

        $template_ids = [];
        $shipping_templates = [];
        //运费模板计算=== start
        if (is_array($template)){
            foreach ($template as $seller_id => $templateRow){
                if (is_array($templateRow)){
                    foreach ($templateRow as $goods_id => $templates){
                        $template_ids[] = $templates['template_id'];
                    }
                }
            }
        }
        //获取所有商品各自对应的运费模板信息
        if ($template_ids){
            $shipping_template = DB::table('shipping_template')
                ->where('is_delete', 0)
                ->whereIn('id', $template_ids)
                ->get()
                ->map(function ($value) {
                    return (array)$value;
                })->toArray();

            if ($shipping_template){
                foreach ($shipping_template as $v){
                    $shipping_templates[$v['id']] = $v;
                }
            }
        }
        //计算运费
        if ($shipping_templates){
            //不同商品使用同一重量运费模板，计算重量总和,再计算运费 start
            foreach ($template as $seller_id => $templateRow){
                foreach ($templateRow as $goods_id => $templates){
                    if (intval($templates['weight']) !== 0) {
                        $template[$seller_id][$goods_id]['weight'] = $templates['weight']*$templates['sum'];
                        $template[$seller_id][$goods_id]['sum'] =1;
                    }
                }
            }

            $template_ids = [];
            $ss = [];
            foreach ($template as $seller_id => $templateRow){
                foreach ($templateRow as $goods_id => $templates){
                    if (intval($templates['weight']) !== 0) {
                        if (in_array($templates['template_id'], $template_ids)) {
                            $template[$seller_id][$ss[$templates['template_id']]]['weight'] += $templates['weight'];
                            unset($template[$seller_id][$goods_id]);
                            continue;
                        }
                        $ss[$templates['template_id']] = $goods_id;
                        $template_ids[] = $templates['template_id'];
                    }
                }
            }
            //不同商品使用同一重量运费模板，计算重量总和,再计算运费 end
            foreach ($template as $seller_id => $templateRow){
                foreach ($templateRow as $goods_id => $templates){
                    if (!$shipping_templates[$templates['template_id']]){
                        throw new \InvalidArgumentException("数据错误"); //t_04
                    }
                    //匹配特殊地区
                    $flags  = 0;
                    $areas   = '';
                    $area_groupid = json_decode($shipping_templates[$templates['template_id']]['area_groupid']);
                    if ($area_groupid){
                        foreach ($area_groupid as $k => $v){
                            $area_groupid_array = explode(",", $v.",");
                            if (in_array($province,$area_groupid_array)){
                                $flags = 1;
                                $areas = $k;
                            }
                        }
                    }
                    if ($shipping_templates[$templates['template_id']]['free_shipping'] == 2){//免运费
                        $templatePrice[$seller_id][$goods_id] = 0;
                        continue;
                    }

                    if (!$shipping_templates[$templates['template_id']]['area_groupid'] || $flags == 0){//使用默认运费计算
                        ///按照重量计算
                        if ($shipping_templates[$templates['template_id']]['type'] == 1){
                            $weight = $templates['weight']*$templates['sum'];
                            $templatePrice[$seller_id][$goods_id] = self::getFeeByWeight2($weight,$shipping_templates[$templates['template_id']]['default_first_weight'],$shipping_templates[$templates['template_id']]['default_first_price'],$shipping_templates[$templates['template_id']]['default_second_weight'],$shipping_templates[$templates['template_id']]['default_second_price']);
                        }

                        //按照袋子计算
                        if ($shipping_templates[$templates['template_id']]['type'] == 2){
                            if ($templates['package_size'] == 1){
                                $templatePrice[$seller_id][$goods_id] = $shipping_templates[$templates['template_id']]['default_big']*$templates['sum'];
                            }elseif ($templates['package_size'] == 2){
                                $templatePrice[$seller_id][$goods_id] = $shipping_templates[$templates['template_id']]['default_middle']*$templates['sum'];
                            }else{
                                $templatePrice[$seller_id][$goods_id] = $shipping_templates[$templates['template_id']]['default_small']*$templates['sum'];
                            }

                        }
                    } else {//按照区域计算运费
                        //按照重量计算
                        if ($shipping_templates[$templates['template_id']]['type'] == 1){
                            $weight = $templates['weight']*$templates['sum'];
                            $area_groupid_infoprice = json_decode($shipping_templates[$templates['template_id']]['area_groupid_infoprice']);
                            $templatePrice[$seller_id][$goods_id] = self::getFeeByWeight2($weight,$area_groupid_infoprice[0][$areas],$area_groupid_infoprice[1][$areas],$area_groupid_infoprice[2][$areas],$area_groupid_infoprice[3][$areas]);
                        }
                        //按照袋子计算
                        if ($shipping_templates[$templates['template_id']]['type'] == 2){
                            $area_groupid_infoprice = json_decode($shipping_templates[$templates['template_id']]['area_groupid_infoprice']);
                            $templatePrice[$seller_id][$goods_id] = $area_groupid_infoprice[$templates['package_size'] - 1][$areas]*$templates['sum'];
                        }
                    }
                }
            }

        //运费模板计算=== end

        }
        //运费模板计算=== end

        //一口价运费和运费模板运费合并处理 start
        if (is_array($freight)){
            foreach ($freight as $seller_id => $priceRow){
                $result['seller_org_price'][$seller_id] = 0;
                $result['seller_price'][$seller_id] = 0;
                $result['shippingcost'][$seller_id] = 0;
                if (is_array($priceRow)){
                    foreach ($priceRow as $goods_id => $price){
                        $result['seller_org_price'][$seller_id] += $price;
                        $result['seller_price'][$seller_id] += $price;
                        $result['shippingcost'][$seller_id] += $price;
                        $result['org_price'] += $price;
                        $result['price'] += $price;
                    }
                }
            }
        }

        if (is_array($templatePrice)){
            foreach ($templatePrice as $seller_id => $priceRow){
                $result['seller_org_price'][$seller_id] = 0;
                $result['seller_price'][$seller_id] = 0;
                $result['shippingcost'][$seller_id] = 0;
                if (is_array($priceRow)){
                    foreach ($priceRow as $goods_id => $price){
                        $result['seller_org_price'][$seller_id] += $price;
                        $result['seller_price'][$seller_id] += $price;
                        $result['shippingcost'][$seller_id] += $price;
                        $result['org_price'] += $price;
                        $result['price'] += $price;
                    }
                }
            }
        }
        //促销规则满足免运费
        if(isset($countSumResult['freeFreight']) && in_array($seller_id,$countSumResult['freeFreight']))
        {
            $result['price'] = 0;
            $result['shippingcost'][$seller_id] = 0;
        }
        //一口价运费和运费模板运费合并处理 end

     	return $result;
	}

    private static function getFeeByWeight2($weight,$firstWeight,$firstFee,$secondWeight,$secondFee)
    {
        //当商品重量为0时免运费
        /*if ($weight == 0){
            return 0;
        }*/

        //当商品重量小于或等于首重的时候
        if($weight <= $firstWeight)
        {
            return $firstFee;
        }
        //当商品重量大于首重时，根据次重进行累加计算
        $num = ceil(($weight - $firstWeight)/$secondWeight);
        return $firstFee + $secondFee * $num;
    }
}