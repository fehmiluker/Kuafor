<?php

namespace Tests\Feature;

use App\Livewire\Public\BookingWizard;
use App\Mail\AppointmentNotificationMail;
use App\Models\CustomerFavorite;
use App\Models\User;
use App\Services\Catalog\RebookService;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\ActsInTenant;
use Tests\Support\BuildsSalon;
use Tests\TestCase;

class BookingLinkTest extends TestCase
{
    use ActsInTenant, BuildsSalon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalon();
    }

    private function stat(string $source, string $column): int
    {
        return (int) DB::table('booking_link_stats')->where('tenant_id', $this->tenant->id)->where('source', $source)->sum($column);
    }

    private function bookViaWizard(?User $user = null)
    {
        if ($user) {
            $this->actingAs($user);
        }

        return Livewire::test(BookingWizard::class, ['slug' => $this->tenant->slug])
            ->call('toggleService', $this->cut->id)->call('toEmployees')->call('selectEmployee', $this->ayse->id)
            ->call('selectTime', '11:00')->set('fullName', 'Zeynep Kaya')->set('phone', '0555 111 22 33')->set('kvkk', true)
            ->call('toSummary')->call('confirm')->assertSet('step', 7);
    }

    public function test_link_redirects_to_booking_and_counts_visits_once_per_session_by_source(): void
    {
        $this->get('/r/'.$this->tenant->slug)->assertRedirect(route('salon.book', $this->tenant->slug));
        $this->get('/r/'.$this->tenant->slug)->assertRedirect();
        $this->assertSame(1, $this->stat('link', 'visits'));

        $this->flushSession();
        $this->get('/r/'.$this->tenant->slug.'?k=qr&sube='.$this->branch->id)->assertRedirect(route('salon.book', ['slug' => $this->tenant->slug, 'sube' => $this->branch->id]));
        $this->assertSame(1, $this->stat('qr', 'visits'));

        $this->flushSession();
        $this->get('/r/'.$this->tenant->slug.'?k=bilinmeyen')->assertRedirect();
        $this->assertSame(2, $this->stat('link', 'visits'), 'unknown source falls back to "link"');

        $this->get('/r/olmayan-salon')->assertNotFound();
    }

    public function test_booking_after_the_link_is_credited_and_the_salon_is_remembered_on_the_device(): void
    {
        $this->get('/r/'.$this->tenant->slug.'?k=qr');
        $this->bookViaWizard();

        $this->assertSame(1, $this->stat('qr', 'bookings'));
        $cookie = Cookie::queued(RebookService::COOKIE);
        $this->assertNotNull($cookie);
        $data = json_decode($cookie->getValue(), true);
        $this->assertSame([$this->tenant->id, $this->branch->id, [$this->cut->id], $this->ayse->id], [$data['t'], $data['b'], $data['s'], $data['e']]);
    }

    public function test_rebook_url_preselects_services_and_employee(): void
    {
        Livewire::withQueryParams(['hizmet' => $this->cut->id.','.$this->color->id, 'personel' => $this->ayse->id])
            ->test(BookingWizard::class, ['slug' => $this->tenant->slug])
            ->assertSet('step', 4)->assertSet('serviceIds', [$this->cut->id, $this->color->id])->assertSet('employeeId', $this->ayse->id);

        // Unknown employee: services kept, employee step shown.
        Livewire::withQueryParams(['hizmet' => (string) $this->cut->id, 'personel' => 999999])
            ->test(BookingWizard::class, ['slug' => $this->tenant->slug])->assertSet('step', 3)->assertSet('employeeId', null);

        // Garbage is ignored.
        Livewire::withQueryParams(['hizmet' => 'abc,999999'])
            ->test(BookingWizard::class, ['slug' => $this->tenant->slug])->assertSet('step', 2)->assertSet('serviceIds', []);
    }

    public function test_guest_home_shows_the_last_salon_from_the_cookie(): void
    {
        $value = json_encode(['t' => $this->tenant->id, 'b' => $this->branch->id, 's' => [$this->cut->id], 'e' => $this->ayse->id, 'at' => now()->timestamp]);
        $expected = route('salon.book', ['slug' => $this->tenant->slug, 'sube' => $this->branch->id, 'hizmet' => $this->cut->id, 'personel' => $this->ayse->id]);

        $this->withCookie(RebookService::COOKIE, $value)->get('/')->assertOk()
            ->assertSee('Son gittiğiniz salon')->assertSee('Tekrar randevu al')->assertSee($expected);
        $this->withCookie(RebookService::COOKIE, '{"t":"x"}')->get('/')->assertOk()->assertDontSee('Son gittiğiniz salon');
    }

    public function test_logged_in_customer_gets_visited_salons_favourite_prompt_and_favourites(): void
    {
        $user = User::factory()->create(['phone_e164' => '+905551112233']);
        $wizard = $this->bookViaWizard($user)->assertSee('Favorilere ekle');
        DB::table('tenant_customers')->where('phone_e164', '+905551112233')->update(['user_id' => $user->id]);

        $this->get(route('account.favorites'))->assertOk()->assertSee('Gittiğiniz salonlar')->assertSee('Tekrar randevu al')
            ->assertSee('personel='.$this->ayse->id, false);

        $wizard->call('addFavorite')->assertSee('favorilerinizde');
        $this->assertTrue(CustomerFavorite::where('user_id', $user->id)->where('tenant_id', $this->tenant->id)->exists());
        $this->get(route('account.favorites'))->assertSee('Favori salonlarım')->assertSee('Favoriden çıkar')->assertDontSee('Gittiğiniz salonlar');
        $this->get('/')->assertSee('Favori ve son salonlarınız')->assertSee($this->tenant->name);
    }

    public function test_owner_share_page_qr_download_and_print_card(): void
    {
        $this->actingAs($this->owner($this->tenant));
        $this->get(route('tenant.share'))->assertOk()->assertSee(route('booking-link', $this->tenant->slug))
            ->assertSee('<svg', false)->assertSee("WhatsApp'ta paylaş", false)->assertSee('Son 30 gün');
        $this->get(route('tenant.share.qr'))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->get(route('tenant.share.print'))->assertOk()->assertSee('Telefonunuzun kamerasıyla okutun', false);
        $this->get(route('tenant.share.qr', ['sube' => 999999]))->assertNotFound();
    }

    public function test_customer_emails_carry_a_new_booking_link(): void
    {
        Mail::fake();
        $a = $this->book($this->at('11:00'), $this->ayse->id, extra: ['customerEmail' => 'zeynep@example.test'])['appointment'];
        app(NotificationDispatcher::class)->appointmentConfirmed($a);

        Mail::assertQueued(AppointmentNotificationMail::class, fn ($m) => $m->rebookUrl === route('booking-link', ['slug' => $this->tenant->slug, 'k' => 'eposta']));
    }
}
