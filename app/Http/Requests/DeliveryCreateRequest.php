<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeliveryCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notification_uuid'      => ['required', 'uuid'],
            'user_uuid'              => ['required', 'uuid'],
            'deliveries'             => ['required', 'array', 'min:1'],
            'deliveries.*.channel'   => ['required', 'in:email,whatsapp,push'],
            'deliveries.*.recipient' => ['nullable', 'string', 'max:255'],
            'deliveries.*.subject'   => ['nullable', 'string', 'max:1000'],
            'deliveries.*.content'   => ['nullable', 'string'],
            'deliveries.*.payload'   => ['nullable', 'array'],
        ];
    }
}
