<?php

namespace App\Notifications;

use App\Models\SalonApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SalonApplicationRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly SalonApplication $application) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Salon başvurunuz hakkında')
            ->greeting('Merhaba '.$this->application->contact_name.',')
            ->line("**{$this->application->business_name}** için yaptığınız başvuruyu şu an için onaylayamadık.")
            ->line('Gerekçe: '.$this->application->rejection_reason)
            ->line('Bilgilerinizi güncelleyerek yeniden başvurabilir veya doğrudan kendi hesabınızı açabilirsiniz.')
            ->action('İşletme hesabı aç', route('register', ['isletme' => 1]));
    }
}
