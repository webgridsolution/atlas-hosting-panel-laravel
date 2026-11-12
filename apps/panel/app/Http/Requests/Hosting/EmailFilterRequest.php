<?php

namespace App\Http\Requests\Hosting;

class EmailFilterRequest extends HostingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'domain_id' => ['required', 'integer'],
            'mailbox' => ['required', 'string', 'max:190'],
            'field' => ['required', 'in:from,to,cc,subject,body,header,x-spam-flag'],
            'operator' => ['required', 'in:contains,equals,matches,starts,ends'],
            'value' => ['required', 'string', 'max:255'],
            'action' => ['required', 'in:discard,redirect,keep,mark_spam,move'],
            'action_arg' => ['nullable', 'string', 'max:255', 'required_if:action,redirect,move'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'action_arg.required_if' => 'A redirect needs a destination address, and a move needs a folder name.',
        ];
    }

    /** The rule shape the agent expects. @return array<string, mixed> */
    public function rule(): array
    {
        return [
            'field' => (string) $this->input('field'),
            'operator' => (string) $this->input('operator'),
            'value' => (string) $this->input('value'),
            'action' => (string) $this->input('action'),
            'action_arg' => (string) $this->input('action_arg', ''),
            'priority' => 10,
        ];
    }
}
