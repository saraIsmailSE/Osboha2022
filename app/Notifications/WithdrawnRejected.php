<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WithdrawnRejected extends Notification implements ShouldQueue
{
    use Queueable;
    protected $url;
    protected $note;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($note)
    {
        $this->note = $note;
        $this->url = 'https://www.messenger.com/t/117840717971244/';
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->from('no-replay@osboha180.com', 'Osboha 180')
            ->subject('أصبوحة || رفض طلبك للانسحاب')
            ->line('تحية طيبة لحضرتك،')
            ->line('طلبك للانسحاب قد تم رفضه للاسباب التالية:')
            ->line($this->note)
            ->line('إذا كنت لا تزال ترغب في الانسحاب، يرجى الاتصال بنا على صفحة الدعم لمزيد من المساعدة')
            ->action('رابط صفحة الدعم', $this->url)
            ->line('لك التحية.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            //
        ];
    }
}
