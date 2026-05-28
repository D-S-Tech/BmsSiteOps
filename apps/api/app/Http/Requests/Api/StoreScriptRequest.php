<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Enums\ScriptLanguage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScriptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'prompt' => ['required', 'string', 'max:5000'],
            'language' => ['required', 'string', Rule::enum(ScriptLanguage::class)],
        ];
    }
}
