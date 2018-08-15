<?php
namespace App\Librarys;
use Illuminate\Support\Facades\DB;

/**
 * @copyright Copyright(c) 2015 aircheng.com
 * @file active.php
 * @brief 促销活动处理类
 * @author nswe
 * @date 2015/5/18 9:08:53
 * @version 3.2
 */
class Active
{
	//活动的类型,groupon(团购),time(限时抢购)
	private $promo;

	//参加活动的用户ID
	private $user_id;

	//活动的ID编号
	private $active_id;

	//商品ID 或 货品ID
	private $id;

	//goods 或 product
	private $type;

	//购买数量
	private $buy_num;

	//原始的商品或者货品数据
	public $originalGoodsInfo;

	//活动价格
	public $activePrice;

	/**
	 * @brief 构造函数创建活动
	 * @param $promo string 活动的类型,groupon(团购),time(限时抢购)
	 * @param $activeId int 活动的ID编号
	 * @param $user_id int 用户的ID编号
	 * @param $id  int 根据$type的不同而表示：商品id,货品id
	 * @param $type string 商品：goods; 货品：product
	 * @param $buy_num int 购买的数量
	 */
	public function __construct($promo,$active_id,$user_id = 0,$id,$type,$buy_num)
	{
		$this->promo     = $promo;
		$this->active_id = $active_id;
		$this->user_id   = $user_id;
		$this->id        = $id;
		$this->type      = $type;
		$this->buy_num   = $buy_num;
	}

	/**
	 * @brief 检查活动的合法性
	 * @return string(有错误) or true(处理正确)
	 */
	public function checkValid()
	{
		if(!$this->id)
		{
            throw new \InvalidArgumentException('Product ID does not exist');
		}
        $dataArray = array();

        //商品方式
        if($this->type == 'goods')
        {
            $goodsData = DB::table('goods as go')
                ->where('id', '=', $this->id)
                ->where('is_del', 0)
                ->select('go.name', 'go.id as goods_id', 'go.img', 'go.sell_price', 'go.point', 'go.weight', 'go.store_nums',
                    'go.exp', 'go.goods_no', DB::raw('0 as product_id') , 'go.seller_id', 'go.is_shipping')
                ->first();
            $goodsData = collect($goodsData)->toArray();
            if($goodsData)
            {
                $goodsData['id'] = $goodsData['goods_id'];
            }
        }
        //货品方式
        else
        {
            $goodsData = $goodsData = DB::table('goods as go')
                ->join('products as pro', 'pro.goods_id', '=', 'go.id')
                ->where('pro.id', '=', $this->id)
                ->where('is_del', 0)
                ->select('pro.sell_price', 'pro.weight', 'pro.id as product_id', 'pro.spec_array', 'pro.goods_id', 'pro.store_nums', 'pro.products_no as goods_no',
                    'go.name', 'go.img', 'go.point', 'go.exp', 'go.seller_id', 'go.is_shipping')
                ->first();
            $goodsData = collect($goodsData)->toArray();
        }
		//库存判断
		if(!$goodsData || $this->buy_num <= 0 || $this->buy_num > $goodsData['store_nums'])
		{
            throw new \InvalidArgumentException('Order is invalid. Please check available stocks.');
		}

		$this->originalGoodsInfo = $goodsData;
		$this->activePrice       = $goodsData['sell_price'];
		$goods_id                = $goodsData['goods_id'];

		//具体促销活动的合法性判断
		switch($this->promo)
		{

			//抢购
			case "time":
			{
                $now = date('Y-m-d H:i:s',time());
                //商品页面根据ID限时抢购
                $promotionRow = DB::table('promotion')
                    ->select('award_value', 'start_time', 'end_time', 'user_group', 'condition')
                    ->where([
                        ['type', '=' , 1],
                        ['id', '=' , $this->active_id],
                        ['start_time', '<=' , $now],
                        ['end_time', '>=' , $now],
                    ])
                    ->first();

                $promotionRow = collect($promotionRow)->toArray();
				if($promotionRow)
				{
					if($promotionRow['condition'] != $goodsData['goods_id'])
					{
                        throw new \InvalidArgumentException('This product is not included in Flash Sale');
					}

                    $memberRow = DB::table('member')->where('user_id', $this->user_id)->select('group_id')->first();
					if($promotionRow['user_group'] == 'all' || (isset($memberRow->group_id) && stripos(','.$promotionRow['user_group'].',',$memberRow->group_id)!==false))
					{
						$this->activePrice = $promotionRow['award_value'];
					}
					else
					{
                        throw new \InvalidArgumentException('This activity is restricted to the user group');
					}
				}
				else
				{
                    throw new \InvalidArgumentException('The activity did not begin, please wait a moment.');
				}

                return true;
			}
			break;
		}
        throw new \InvalidArgumentException('Invalid Promotion.');
	}

	/**
	 * @brief 促销活动对应order_type的值
	 */
	public function getOrderType()
	{
		$result = array('groupon' => 1,'time' => 2);
		return isset($result[$this->promo]) ? $result[$this->promo] : 0;
	}

}