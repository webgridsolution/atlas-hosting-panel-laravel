<?php

namespace App\Http\Requests\Hosting;

use App\Models\PhpSetting;

class PhpSettingsRequest extends HostingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'domain_id' => ['required', 'integer'],
            'php_version' => ['required', 'in:'.implode(',', $this->phpVersions())],
        ];

        // Only the directives the agent will accept are validated here too, so
        // the error appears next to the field instead of as a 403 from the
        // agent halfway through saving.
        foreach (PhpSetting::EDITABLE as $directive) {
            $rules['settings.'.$directive] = ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.:\/-]+$/'];
        }

        return $rules;
    }
}
