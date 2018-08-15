<?php
$router->get('home/banner','HomeController@banner');//首页banner图
$router->get('home/flashSale','HomeController@flashSale');//首页限时抢购版块
$router->get('home/flashSaleCate','HomeController@flashSaleCate');//首页限时抢购模块see all顶部分类
$router->get('home/flashSaleList','HomeController@flashSaleList');//首页限时抢购模块see all列表数据
$router->get('home/groupBuy','HomeController@groupBuy');//首页拼单版块
$router->get('home/groupList','HomeController@groupList');//首页拼单版块see all
$router->get('home/groupTipsList','HomeController@groupTipsList');//首页拼单版块see all 拼单滚动拼单
$router->get('home/categoryBlock','HomeController@categoryBlock');//首页分类版块
$router->get('home/getRecom','HomeController@getRecom');//首页推荐商品版块
$router->get('home/getShopList','HomeController@getShopList');//首页推荐店铺版块
$router->get('home/getRecommendedBrand','HomeController@getRecommendedBrand');//首页分类推荐品牌