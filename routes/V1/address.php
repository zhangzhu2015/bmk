<?php

$router->put('address/setDefault/{address_id}','AddressController@setDefault'); //设置默认地址
$router->post('address/store','AddressController@store'); //新增地址
$router->put('address/update/{address_id}','AddressController@update'); //编辑地址
$router->delete('address/delete/{address_id}','AddressController@delete'); //删除地址
