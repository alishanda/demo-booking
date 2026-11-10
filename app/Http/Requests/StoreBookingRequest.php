<?php

namespace App\Http\Requests;

use App\Dto\CreateBookingData;
use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function toDto(): CreateBookingData
    {
        return CreateBookingData::fromRequest($this);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'room_id.required' => 'Укажите комнату.',
            'room_id.exists' => 'Комната не найдена.',
            'title.required' => 'Укажите название встречи.',
            'starts_at.required' => 'Укажите время начала.',
            'starts_at.date' => 'Некорректное время начала.',
            'ends_at.required' => 'Укажите время окончания.',
            'ends_at.date' => 'Некорректное время окончания.',
            'ends_at.after' => 'Время окончания должно быть позже начала.',
        ];
    }
}
