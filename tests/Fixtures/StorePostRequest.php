<?php

namespace Toshkq93\BehaviorDiffLaravel\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

class StorePostRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:100',
            'user_id' => 'required|exists:users,id',
            'status' => 'in:draft,published',
        ];
    }
}
