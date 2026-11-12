<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCronRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Five fields, or a supported macro. The agent rejects newlines:
            // a cron entry is one line by definition.
            'schedule' => ['required', 'string', 'max:120', 'regex:/^(@(hourly|daily|weekly|monthly|yearly|reboot)|(\S+\s+){4}\S+)$/'],
            'command' => ['required', 'string', 'max:1000', 'not_regex:/[\r\n]/'],
            'description' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'schedule.regex' => 'Use five cron fields (minute hour day month weekday) or a macro such as @daily.',
            'command.not_regex' => 'A cron command must fit on one line.',
        ];
    }
}
