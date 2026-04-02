<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * EventRequest validates POST /event payloads before they reach the controller.
 *
 * Rules are conditional on the event type so we only require what each
 * operation actually needs (e.g. deposit does not have an "origin" field).
 */
class EventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $type = $this->input('type');

        $rules = [
            'type'   => ['required', 'string', 'in:deposit,withdraw,transfer'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ];

        if (in_array($type, ['deposit', 'transfer'], strict: true)) {
            $rules['destination'] = ['required', 'string'];
        }

        if (in_array($type, ['withdraw', 'transfer'], strict: true)) {
            $rules['origin'] = ['required', 'string'];
        }

        return $rules;
    }

    /**
     * Return "0" with 422 on validation failure, consistent with the
     * 404 pattern used for "not found" cases throughout the API.
     */
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response('0', 422));
    }
}
