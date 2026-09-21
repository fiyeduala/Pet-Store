<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Refund;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent only once the provider has CONFIRMED the refund was issued.
 * A refund request never triggers this.
 */
class RefundProcessed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Refund $refund) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Refund sent for order '.$this->refund->order->number)
            ->markdown('emails.refund-processed', [
                'refund' => $this->refund,
                'order' => $this->refund->order,
            ]);
    }
}
