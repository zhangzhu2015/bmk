<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class UnFollowRequest extends FormRequest
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
            'type'=> 'required',
            'seller_id'=> 'required',
        ];
    }

    public function messages() {
        return [
            'type.required' => "Type can't be empty",
            'seller_id.required' => "Seller Id can't be empty",
        ];
    }
}
