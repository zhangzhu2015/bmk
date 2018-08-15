<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class AddressRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'accept_name'=> 'required',
            'province'=> 'required',
            'city'=> 'required',
            'area'=> 'required',
            'address'=> 'required',
            'mobile'=> 'required|is_mobile',
        ];
    }

    public function messages() {
        return [
            'accept_name.required'=> "Name can't be empty",
            'province.required'=> "Province can't be empty",
            'city.required'=> "City can't be empty",
            'area.required'=> "Area can't be empty",
            'address.required'=> "Shipping address can't be empty",
            'mobile.required'=> "Phone number can't be empty",
        ];
    }
}
