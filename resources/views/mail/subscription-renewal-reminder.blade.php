<x-mail::message>
# {{ __('Your subscription renews soon') }}

{{ __('Business') }}: **{{ $tenantName }}** <br>
{{ __('Plan') }}: **{{ $planName }}** <br>
{{ __('Renewal date') }}: **{{ $periodEnd }}** <br>

{{ __('Your current subscription period ends soon. Contact us if you\'d like to change your plan or have any questions.') }}

{{ __('This is an automated reminder.') }}
</x-mail::message>
