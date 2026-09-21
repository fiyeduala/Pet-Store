<x-mail::message>
# Your order {{ $order->number }}

Someone (we hope you) asked to see this order. Use the secure link below —
it expires in seven days and can only be used for this one order.

<x-mail::button :url="$url">
View your order
</x-mail::button>

If you did not ask for this, you can ignore this email. Nothing has changed
on your order and no one can see it without this link.

Thanks,<br>
{{ $order->brand_snapshot['name'] ?? branding('name') }}

<x-slot:subcopy>
If the button does not work, copy and paste this address into your browser:
{{ $url }}
</x-slot:subcopy>
</x-mail::message>
