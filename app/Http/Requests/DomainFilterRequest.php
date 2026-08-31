<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DomainFilterRequest extends FormRequest
{
    public const SORTABLE_COLUMNS = [
        'domain',
        'tld',
        'registrar',
        'renewal_price',
        'registered_at',
        'expires_at',
        'remaining_days',
        'status',
        'is_locked',
        'privacy_enabled',
        'auto_renew',
        'nameserver_1',
        'nameserver_2',
    ];

    public const PER_PAGE_OPTIONS = [25, 50, 100, 250, 500];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:253'],
            'account' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'string', Rule::in(self::SORTABLE_COLUMNS)],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in(self::PER_PAGE_OPTIONS)],
        ];
    }
}
