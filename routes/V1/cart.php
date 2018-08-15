<?php
Route::get('cart/list', 'CartController@list');//购物车列表
Route::post('cart/changeCartNum', 'CartController@changeCartNum');//修改购物车商品数量
Route::post('cart/removeCart', 'CartController@removeCart');//删除购物车商品
Route::post('cart/cartsVoucher', 'CartController@cartsVoucher');//购物车店铺优惠券
Route::post('cart/cartCheckOut','CartController@cartCheckOut'); //购物车检出
Route::post('cart/createOrderBn','CartController@createOrderBn'); //提交订单(立即购买)
Route::post('cart/createOrderCart','CartController@createOrderCart'); //提交订单（购物车）