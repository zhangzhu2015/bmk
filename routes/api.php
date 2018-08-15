<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/



require(__DIR__ . '/V1/auth.php');

Route::group(['namespace'=>'V1','prefix'=>'v1'], function($router) {
    require(__DIR__ . '/V1/help.php');
    require(__DIR__ . '/V1/home.php');
    require(__DIR__ . '/V1/goods.php');
    $router->get('common/getPaymentList','CommonController@getPaymentList'); //获取支付方式
    $router->post('common/getChildrenByAreaId','CommonController@getChildrenByAreaId'); //省市区
    $router->get('common/getclist','CommonController@getclist'); //网站分类
    $router->get('common/goodsList','CommonController@goodsList'); //商品列表(搜索)
    $router->get('common/getBuyerStartImg','CommonController@getBuyerStartImg'); //启动图
    $router->get('common/getBuyerGuideImg','CommonController@getBuyerGuideImg'); //引导图
    $router->get('common/getAppDialog','CommonController@getAppDialog'); //更新日志
    $router->get('common/shop_list','CommonController@shop_list'); //店铺列表
    $router->get('group/youLike','GroupController@youLike'); //拼单你也许喜欢

	$router->get('test/groupBuy','TestController@groupBuy');//首页拼单版块
	$router->get('test/groupList','TestController@groupList');//首页拼单版块see all
});

Route::group(['namespace'=>'V1','prefix'=>'v1', 'middleware'=> 'auth:api'], function($router) {
    require(__DIR__ . '/V1/common.php');
    require(__DIR__ . '/V1/user.php');
    require(__DIR__ . '/V1/discover.php');
    require(__DIR__ . '/V1/cart.php');
    require(__DIR__ . '/V1/group.php');
    require(__DIR__ . '/V1/address.php');
    require(__DIR__ . '/V1/quota.php');
});