<?php

/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018/7/24 0024
 * Time: 10:48
 */
$router->get('quota/help_list','QuotaController@help_list'); //帮助列表
$router->get('quota/help_detail','QuotaController@help_detail'); //帮助详情
$router->get('quota/order_tips','QuotaController@order_tips'); //订单提示消息
$router->get('quota/goods_categories','QuotaController@goods_categories'); //拼单商品分类
$router->post('quota/quotaList', 'QuotaController@quotaList');//拼单列表
$router->get('quota/quotaDetail/{id}', 'QuotaController@quotaDetail');//拼单详情（个人中心）