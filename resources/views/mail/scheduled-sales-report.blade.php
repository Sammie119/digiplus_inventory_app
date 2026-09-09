<x-mail::message>
# {{ __('Sales Report') }}

{{ __('Branch') }}: **{{ $branchName }}** <br>
{{ __('Period') }}: **{{ $from }} – {{ $to }}** <br>

<x-mail::table>
| {{ __('Sales') }} | {{ __('Revenue') }} | {{ __('Cost') }} | {{ __('Margin') }} | {{ __('Margin %') }} |
| :- | :- | :- | :- | :- |
| {{ $summary['count'] }} | {{ number_format($summary['revenue'], 2) }} | {{ number_format($summary['cost'], 2) }} | {{ number_format($summary['margin'], 2) }} | {{ number_format($summary['margin_pct'], 1) }}% |
</x-mail::table>

{{ __('The full report is attached as a PDF.') }}

{{ __('This is an automated message from your Scheduled Reports settings.') }}
</x-mail::message>
