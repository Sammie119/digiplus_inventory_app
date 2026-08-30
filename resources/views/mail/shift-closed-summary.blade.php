<x-mail::message>
# {{ __('Shift Closed') }}

{{ __('Cashier') }}: **{{ $summary['cashier_name'] }}**
{{ __('Branch') }}: **{{ $summary['branch_name'] }}**
{{ __('Opened') }}: **{{ $summary['opened_at']->format('d M Y, H:i') }}**
{{ __('Closed') }}: **{{ $summary['closed_at']->format('d M Y, H:i') }}**

<x-mail::table>
| {{ __('Sales') }} | {{ __('Total Sales') }} |
| :- | :- |
| {{ $summary['sales_count'] }} | {{ number_format($summary['total_sales'], 2) }} |
</x-mail::table>

<x-mail::table>
| {{ __('Cash') }} | {{ __('Mobile Money') }} | {{ __('Card') }} | {{ __('Credit') }} |
| :- | :- | :- | :- |
| {{ number_format($summary['cash_total'], 2) }} | {{ number_format($summary['mobile_money_total'], 2) }} | {{ number_format($summary['card_total'], 2) }} | {{ number_format($summary['credit_total'], 2) }} |
</x-mail::table>

<x-mail::table>
| {{ __('Opening Float') }} | {{ __('Expected Cash') }} | {{ __('Counted Cash') }} | {{ __('Variance') }} |
| :- | :- | :- | :- |
| {{ number_format($summary['opening_float'], 2) }} | {{ number_format($summary['expected_cash'], 2) }} | {{ number_format($summary['closing_float_counted'], 2) }} | {{ number_format($summary['variance'], 2) }} |
</x-mail::table>

{{ __('This is an automated message sent when a cashier closes their shift.') }}
</x-mail::message>
