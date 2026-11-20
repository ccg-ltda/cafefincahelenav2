<?php

namespace App\Mail;

use App\Models\Order;
use App\Support\OrderEmailData;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Any transactional email of an order (customer or administrator): one Mailable, the type picks the wording. Sent only from SendOrderNotification. */
class OrderNotificationMail extends Mailable
{
    public function __construct(public readonly Order $order, public readonly string $type) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: OrderEmailData::subject($this->order, $this->type));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.order', text: 'emails.order-text', with: ['d' => OrderEmailData::for($this->order, $this->type)]);
    }
}
