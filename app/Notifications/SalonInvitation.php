<?php

namespace App\Notifications;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent when the platform creates a salon for its owner (bulk import or approved application). */
class SalonInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Tenant $tenant, public readonly ?string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->tenant->name.' için işletme paneliniz hazır')
            ->greeting('Merhaba '.$notifiable->name.',')
            ->line("**{$this->tenant->name}** işletmeniz ".config('app.name').' platformunda oluşturuldu. Panelden hizmetlerinizi, personelinizi ve çalışma saatlerinizi düzenleyebilir, online randevu almaya başlayabilirsiniz.');

        if ($this->token) {
            return $mail->line('Başlamak için aşağıdaki bağlantıdan şifrenizi belirleyin. Bağlantı 7 gün geçerlidir.')
                ->action('Şifremi belirle', route('invite.show', ['token' => $this->token, 'email' => $notifiable->email]))
                ->line('Bu işletmeyi siz eklemediyseniz bu e-postayı yok sayabilir ve bize bildirebilirsiniz.');
        }

        return $mail->line('Mevcut hesabınızla giriş yapıp işletme seçiminden yeni işletmenize geçebilirsiniz.')
            ->action('Panele git', route('tenant.select'));
    }
}
