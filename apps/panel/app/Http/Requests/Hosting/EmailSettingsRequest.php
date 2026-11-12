<?php

namespace App\Http\Requests\Hosting;

class EmailSettingsRequest extends HostingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'domain_id' => ['required', 'integer'],
            'spam_threshold' => ['required', 'numeric', 'min:0', 'max:20'],
            'spam_action' => ['required', 'in:mark,discard,move'],
            'dkim_enabled' => ['boolean'],
            'spf_enabled' => ['boolean'],
            'greylisting' => ['boolean'],
            'mailbox_quota_mb' => ['required', 'integer', 'min:64', 'max:102400'],
        ];
    }
}
