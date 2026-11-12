<?php

namespace App\Http\Requests\Hosting;

class DatabaseUserRequest extends HostingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:20', 'regex:/^[a-z][a-z0-9_]*$/'],
            'privileges' => ['required', 'in:ALL,READ,WRITE'],
            'databases' => ['required', 'array', 'min:1'],
            'databases.*' => ['integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.regex' => 'A database user name starts with a letter and uses lowercase letters, digits and underscores.',
            'databases.required' => 'A database user needs access to at least one database.',
        ];
    }
}
