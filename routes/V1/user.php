<?php
$router->get('user/userInfo','UserController@userInfo'); //获取个人信息
$router->put('user/restPassword','UserController@restPassword'); //修改密码
$router->get('user/myAddress','UserController@myAddress'); //我的地址
$router->put('user/userInfo','UserController@updateUserInfo'); //修改个人信息
$router->get('user/followList','UserController@followList'); //店铺关注列表
$router->put('user/follow','UserController@follow'); //取消/关注 店铺

$router->get('user/orderList','UserController@orderList'); //获取订单列表
$router->get('user/orderDetail/{id}','UserController@orderDetail'); //订单详情
$router->put('user/cancelOrder/{id}','UserController@cancelOrder'); //取消订单
$router->put('user/confirmReceipt/{id}','UserController@confirmReceipt'); //确认收货
$router->put('user/reminderSlip/{id}','UserController@reminderSlip'); //发货提醒
$router->get('user/getRecentlyGoods','UserController@getRecentlyGoods'); //最近浏览商品

$router->get('user/fList','UserController@favoriteList');//收藏列表
$router->post('user/addOrCancel','UserController@addOrCancel');//收藏or取消收藏
$router->post('user/addComment','UserController@comments');//订单添加评论
$router->get('user/messList','UserController@messGroupList');//消息分组
$router->get('user/sysList','UserController@messSMList');//系统消息列表
$router->post('user/sysCont','UserController@messSysContent');//系统消息内容
$router->post('user/isRead','UserController@isRead');//消息读否
$router->get('user/ordList','UserController@messONList');//订单消息列表
$router->post('user/voList','UserController@voucherList');//优惠券列表
$router->post('user/voDetail','UserController@voucherDetail');//优惠券详情
$router->post('user/voGet','UserController@voucherGet');//获取优惠券
$router->post('user/creCode','UserController@createCode');//生成赠送口令

$router->post('user/getQA','UserController@operateDeliveryAddress');//拼单收货地址
$router->post('user/orderSuc','UserController@orderSuccessPage');//下单成功页
$router->post('user/orderVou','UserController@orderVoucher');//下单可使用优惠券

$router->post('user/commit_suggestion','UserController@commit_suggestion'); //买家反馈

