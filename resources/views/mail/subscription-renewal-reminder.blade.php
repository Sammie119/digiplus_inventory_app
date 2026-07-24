<x-mail::message>
# {{ __('Your subscription renews soon') }}

{{ __('Business') }}: **{{ $tenantName }}**
{{ __('Plan') }}: **{{ $planName }}**
{{ __('Renewal date') }}: **{{ $periodEnd }}**

{{ __('Your current subscription period ends soon. Contact us if you\'d like to change your plan or have any questions.') }}

{{ __('This is an automated reminder.') }}
</x-mail::message>
