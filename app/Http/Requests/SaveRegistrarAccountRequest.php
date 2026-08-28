<?php

namespace App\Http\Requests;

use App\Enums\RegistrarEnvironment;
use App\Enums\RegistrarProvider;
use App\Models\RegistrarAccount;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveRegistrarAccountRequest extends FormRequest
{
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
            'provider' => ['required', Rule::enum(RegistrarProvider::class)],
            'environment' => ['required', Rule::enum(RegistrarEnvironment::class)],
            'label' => ['required', 'string', 'max:255', Rule::unique('registrar_accounts')->ignore($this->account())],
            'username' => ['required', 'string', 'max:255', Rule::when($this->string('provider')->toString() === RegistrarProvider::ZCom->value, ['email'])],
            'api_user' => ['nullable', 'string', 'max:255'],
            'client_ipv4' => ['nullable', Rule::when($this->string('provider')->toString() === RegistrarProvider::Namecheap->value, ['required', 'ipv4'], ['ipv4'])],
            'secret' => [$this->account() ? 'nullable' : 'required', 'string', 'max:2048'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $provider = RegistrarProvider::tryFrom($this->string('provider')->toString());
            $account = $this->account();

            if ($account && $provider !== $account->provider) {
                $validator->errors()->add('provider', 'The provider cannot be changed after an account is created.');
            }

            if ($provider === RegistrarProvider::ZCom && $this->string('environment')->toString() !== RegistrarEnvironment::Production->value) {
                $validator->errors()->add('environment', 'Z.com only supports the production environment.');
            }

            if (! $account && $provider === RegistrarProvider::ZCom && ! config('services.zcom.enabled')) {
                $validator->errors()->add('provider', 'Z.com browser automation is not enabled.');
            }
        }];
    }

    /** @return array<string, mixed> */
    public function accountData(): array
    {
        $validated = $this->validated();
        $provider = RegistrarProvider::from($validated['provider']);
        $account = $this->account();
        $credentials = $account?->credentials ?? [];
        $secret = (string) ($validated['secret'] ?? '');

        if ($secret !== '') {
            $credentials = match ($provider) {
                RegistrarProvider::Namecheap => ['api_key' => $secret],
                RegistrarProvider::NameCom => ['token' => $secret],
                RegistrarProvider::ZCom => ['password' => $secret],
            };
        } elseif ($provider === RegistrarProvider::ZCom && $account && $account->username !== $validated['username']) {
            unset($credentials['storage_state']);
        }

        return [
            'provider' => $provider,
            'environment' => RegistrarEnvironment::from($validated['environment']),
            'label' => $validated['label'],
            'username' => $validated['username'],
            'api_user' => $provider === RegistrarProvider::Namecheap ? ($validated['api_user'] ?? null) : null,
            'client_ipv4' => $provider === RegistrarProvider::Namecheap ? ($validated['client_ipv4'] ?? null) : null,
            'credentials' => $credentials,
            'is_active' => $validated['is_active'],
        ];
    }

    private function account(): ?RegistrarAccount
    {
        $account = $this->route('registrarAccount');

        return $account instanceof RegistrarAccount ? $account : null;
    }
}
