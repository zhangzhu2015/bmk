<?php

namespace App\Http\Requests\v1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GoodsInfoRequest extends FormRequest
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
            'id'=> [
                'required',
                Rule::exists('goods')->where(function ($query) {
                    $query->where('is_del', 0);
                }),
            ]
        ];
    }

    public function messages() {
        return [
            'id.required' => "Product Id can't be empty",
            'id.exists' => "Product does not exist",
        ];
    }
}
