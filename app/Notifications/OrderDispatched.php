<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class OrderDispatched extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Collection<int, \App\Models\Shipment>  $shipments
     */
    public function __construct(
        public readonly Order $order,
        public readonly Collection $shipments,
        public readonly ?string $trackingUrl = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Order '.$this->order->number.' is on its way')
            ->markdown('emails.order-dispatched', [
                'order' => $this->order->loadMissing('shipments'),
                'shipments' => $this->shipments,
                'trackingUrl' => $this->trackingUrl,
            ]);
    }
}
