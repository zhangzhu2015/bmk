<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018/7/25 0025
 * Time: 11:43
 */

$router->post('group/createGroup','GroupController@createGroup'); //发起/加入拼单校验;提交拼单,生成拼单数
$router->get('group/groupInfo','GroupController@groupInfo'); //拼单详情 拼单商品页面中间部分，显示关于这个商品的拼单和控制按钮显示
$router->get('group/inviteGroup','GroupController@inviteGroup'); //邀请拼单 生成邀请代码
$router->get('group/groupOrderGoods','GroupController@groupOrderGoods'); //拼单提交页面显示商品信息
$router->get('group/groupAddressList','GroupController@groupAddressList'); //拼单收货地址