<?php
$router->post('common/sellerInfo','CommonController@sellerInfo'); //店铺信息
$router->get('common/getUserFavoritesNum','CommonController@getUserFavoritesNum'); //我的收藏数量
$router->post('common/getShippingFee','CommonController@getShippingFee'); //获取运费
$router->get('common/getRecentlyGoods','CommonController@getRecentlyGoods'); //浏览足迹
$router->post('common/likeGoods','CommonController@likeGoods'); //商品点赞
$router->post('common/editAddress','CommonController@editAddress'); //编辑地址
$router->get('common/countMess','CommonController@countMess'); //消息未读总数
$router->post('common/uploadImage','CommonController@uploadImage'); //上传图片
$router->get('common/getCartNum','CommonController@getCartNum'); //购物车数量




