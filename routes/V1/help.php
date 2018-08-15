<?php
$router->get('help/header','HelpController@header_info'); //获取帮助中心头部信息
$router->get('help/footer','HelpController@footer'); //获取帮助中心底部信息
$router->get('help/hot_questions/{type}','HelpController@hot_questions'); //获取帮助中心常见问题
$router->get('help/categories/{type}','HelpController@categories'); //获取帮助中心问题分类
$router->get('help/list/{type}/{cate_id?}','HelpController@lists'); //获取帮助列表
$router->get('help/search/{type}/{keyword}','HelpController@search'); //搜索帮助列表
$router->get('help/detail/{id}','HelpController@detail'); //获取帮助详情
$router->put('help/{id}','HelpController@update'); //点赞or点down
