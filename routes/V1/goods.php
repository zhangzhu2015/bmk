<?php

$router->post('goods/goodsInfo','GoodsController@goodsInfo');  //获取商品详情
$router->post('goods/getFeedback','GoodsController@getFeedback');  //获取商品评论
$router->get('Shop/shopFlashList','shopController@shopFlashList'); //店铺限时抢购列表
$router->get('Shop/shopCategories','shopController@shopCategories'); //店铺分类
$router->get('Shop/shopSaleList','shopController@shopSaleList'); //店铺特价商品

$router->post('goods/joinCart','GoodsController@joinCart')->middleware('auth:api');  //加入购物车
$router->post('goods/buyNow','GoodsController@buyNow')->middleware('auth:api'); //立即购买