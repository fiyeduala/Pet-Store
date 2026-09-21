<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderPaid extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Order $order,
        public readonly ?string $trackingUrl = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            // Branding comes from the order's snapshot, so a later rebrand
            // does not rewrite what this customer was told.
            ->subject(($this->order->brand_snapshot['name'] ?? branding('name')).' — order '.$this->order->number.' confirmed')
            ->markdown('emails.order-paid', [
                'order' => $this->order->loadMissing('items'),
                'trackingUrl' => $this->trackingUrl,
            ]);
    }
}
