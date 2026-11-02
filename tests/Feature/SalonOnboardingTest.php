<?php

namespace Tests\Feature;

use App\Enums\SalonApplicationStatus;
use App\Models\City;
use App\Models\District;
use App\Models\SalonApplication;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SalonApplicationRejected;
use App\Notifications\SalonInvitation;
use App\Services\Onboarding\SalonCsvImporter;
use Database\Seeders\GeoSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActsInTenant;
use Tests\TestCase;

class SalonOnboardingTest extends TestCase
{
    use ActsInTenant;

    private const CSV = "isletme_adi;yetkili_adi;eposta;telefon;il;ilce;adres;paket;aciklama;hizmetler;personel\n"
        ."Işık Kuaför;Ayşe Yılmaz;ayse@isik.test;0532 111 22 33;Bursa;Nilüfer;Fethiye Mah. Örnek Sk. No:1;profesyonel;Kadın kuaförü;Kadın Saç Kesimi:45:500|Fön:30:250,50;Ayşe|Zeynep\n"
        ."Yeni Berber;Ali Kaya;ali@berber.test;;Çorum;Merkez;Çarşı Cad. No:5;;;;\n"
        ."Hatalı Salon;Veli;gecersiz-eposta;;Atlantis;;Adres yok;;;Kesim:abc:100;\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GeoSeeder::class);
        Notification::fake();
    }

    private function previewToken(string $csv = self::CSV): string
    {
        $res = $this->post(route('admin.onboarding.preview'), ['file' => UploadedFile::fake()->createWithContent('salonlar.csv', $csv)])->assertOk();

        return $res->viewData('token');
    }

    public function test_only_platform_admins_reach_the_module(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.onboarding.import'))->assertForbidden();
        $this->asAdminWeb()->get(route('admin.onboarding.import'))->assertOk()->assertSee('Şablonu indirin');
        $this->get(route('admin.onboarding.template'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertSee('"isletme_adi";"yetkili_adi"', false);
        $this->get(route('admin.onboarding.applications'))->assertOk();
    }

    public function test_preview_validates_rows_and_import_creates_salons_with_invitations(): void
    {
        $this->asAdminWeb();
        $res = $this->post(route('admin.onboarding.preview'), ['file' => UploadedFile::fake()->createWithContent('salonlar.csv', self::CSV)])->assertOk()
            ->assertSee('2 aktarılabilir')->assertSee('E-posta geçersiz')->assertSee('İl bulunamadı')->assertSee('Yeni ilçe eklenecek: Merkez');
        $this->assertSame(0, Tenant::count(), 'preview must not write anything');

        $this->post(route('admin.onboarding.run'), ['token' => $res->viewData('token'), 'invite' => 1])
            ->assertRedirect(route('admin.onboarding.import'))->assertSessionHas('status', '2 salon eklendi. 1 hatalı satır atlandı.');

        $isik = Tenant::where('name', 'Işık Kuaför')->firstOrFail();
        $owner = User::findOrFail($isik->owner_user_id);
        $this->assertNull($owner->password);
        $this->assertSame('+905321112233', $owner->phone_e164);
        $this->assertTrue($isik->public_listing_enabled);
        $this->assertSame('profesyonel', $isik->subscription->plan->slug);
        $this->assertSame(2, DB::table('services')->where('tenant_id', $isik->id)->count());
        $this->assertSame('250.50', DB::table('services')->where('tenant_id', $isik->id)->where('name', 'Fön')->value('base_price'));
        $this->assertSame('fon-sekillendirme', DB::table('service_categories')->where('id', DB::table('services')->where('name', 'Fön')->value('category_id'))->value('slug'));
        $this->assertSame(2, DB::table('employee_services')->where('tenant_id', $isik->id)->distinct()->count('employee_id'));
        $this->assertSame('nilufer', DB::table('districts')->where('id', DB::table('branches')->where('tenant_id', $isik->id)->value('district_id'))->value('slug'));

        $berber = Tenant::where('name', 'Yeni Berber')->firstOrFail();
        $this->assertFalse($berber->public_listing_enabled, 'no services yet => not listed');
        $this->assertSame('standart', $berber->subscription->plan->slug);
        $this->assertTrue(District::where('city_id', City::where('slug', 'corum')->value('id'))->where('slug', 'merkez')->exists());

        Notification::assertSentTo($owner, SalonInvitation::class);
        Notification::assertSentTimes(SalonInvitation::class, 2);

        // Same file again: everything is already there.
        $this->post(route('admin.onboarding.preview'), ['file' => UploadedFile::fake()->createWithContent('salonlar.csv', self::CSV)])
            ->assertSee('0 aktarılabilir')->assertSee('Bu işletme zaten kayıtlı');
    }

    public function test_invitation_link_sets_the_password_and_the_owner_can_log_in(): void
    {
        $this->asAdminWeb()->post(route('admin.onboarding.run'), ['token' => $this->previewToken(), 'invite' => 1]);
        $owner = User::where('email', 'ayse@isik.test')->firstOrFail();
        $token = null;
        Notification::assertSentTo($owner, SalonInvitation::class, function (SalonInvitation $n) use (&$token, $owner) {
            $token = $n->token;

            return str_contains($n->toMail($owner)->actionUrl, '/davet/');
        });
        auth()->logout();

        $this->get(route('invite.show', ['token' => $token, 'email' => $owner->email]))->assertOk()->assertSee('İşletme panelinize hoş geldiniz');
        $this->post(route('invite.accept'), ['token' => $token, 'email' => $owner->email, 'password' => 'YeniSifre123', 'password_confirmation' => 'YeniSifre123'])
            ->assertRedirect(route('login'));
        $this->assertNotNull($owner->refresh()->email_verified_at);
        $this->post(route('login'), ['email' => $owner->email, 'password' => 'YeniSifre123'])->assertRedirect();
        $this->assertAuthenticatedAs($owner);
    }

    public function test_plan_limit_failure_is_reported_and_nothing_partial_is_left(): void
    {
        $csv = "isletme_adi;yetkili_adi;eposta;il;adres;paket;hizmetler;personel\n"
            ."Kalabalık Salon;Can Er;can@salon.test;Bursa;Uzun Cad. No:3;standart;Kesim:30:300;A|B|C\n";
        $this->asAdminWeb()->post(route('admin.onboarding.run'), ['token' => $this->previewToken($csv)])
            ->assertSessionHas('status', '0 salon eklendi. 1 satır eklenemedi.')->assertSessionHas('import_failed');
        $this->assertSame(0, Tenant::count());
        $this->assertFalse(User::where('email', 'can@salon.test')->exists());
    }

    public function test_turkish_excel_csv_with_commas_and_windows_1254_is_read(): void
    {
        $csv = mb_convert_encoding("İşletme Adı,Yetkili Adı,E-posta,İl,Adres\nGül Kuaför,Şule Öz,sule@gul.test,İzmir,Kıbrıs Şehitleri Cad. No:2\n", 'Windows-1254', 'UTF-8');
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $csv);
        $rows = app(SalonCsvImporter::class)->parse($path);
        unlink($path);

        $this->assertCount(1, $rows);
        $this->assertSame([], $rows[0]['errors']);
        $this->assertSame('Gül Kuaför', $rows[0]['data']['business_name']);
        $this->assertSame('Şule Öz', $rows[0]['data']['owner_name']);
    }

    public function test_cli_preview_and_import(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, self::CSV);
        $this->artisan('kuafor:salon-aktar', ['dosya' => $path, '--onizle' => true])->expectsOutputToContain('3 satır: 2 geçerli, 1 hatalı.')->assertSuccessful();
        $this->assertSame(0, Tenant::count());
        $this->artisan('kuafor:salon-aktar', ['dosya' => $path, '--davet' => true])->expectsOutputToContain('2 salon eklendi.')->assertSuccessful();
        unlink($path);
        Notification::assertSentTimes(SalonInvitation::class, 2);
    }

    // ------------------------------------------------------------ applications

    private function applicationData(array $over = []): array
    {
        return array_merge([
            'business_name' => 'Papatya Güzellik', 'contact_name' => 'Derya Kurt', 'email' => 'Derya@Papatya.test', 'phone' => '0533 444 55 66',
            'city_id' => City::where('slug', 'bursa')->value('id'), 'district_name' => 'Gürsu', 'address' => 'Kurtuluş Mah. No:7',
            'categories' => ['cilt-bakimi', 'epilasyon'], 'employee_count' => 3, 'kvkk_acknowledged' => 1,
        ], $over);
    }

    public function test_public_application_is_stored_and_footer_links_to_it(): void
    {
        $this->get('/')->assertSee(route('salon-application.create'));
        $this->get(route('salon-application.create'))->assertOk()->assertSee('Başvuru formu')->assertSee('İşletme hesabı aç');
        $this->post(route('salon-application.store'), $this->applicationData())->assertRedirect(route('salon-application.create'))->assertSessionHas('status');
        $this->post(route('salon-application.store'), $this->applicationData()); // duplicate pending: not stored twice

        $a = SalonApplication::sole();
        $this->assertSame('derya@papatya.test', $a->email);
        $this->assertSame('+905334445566', $a->phone_e164);
        $this->assertSame(SalonApplicationStatus::Pending, $a->status);
        $this->assertSame(['cilt-bakimi', 'epilasyon'], $a->categories);

        $this->post(route('salon-application.store'), $this->applicationData(['business_name' => 'Bot', 'website' => 'http://spam.test']));
        $this->assertSame(1, SalonApplication::count());
        $this->post(route('salon-application.store'), $this->applicationData(['business_name' => 'Rızasız', 'kvkk_acknowledged' => null]))->assertSessionHasErrors('kvkk_acknowledged');
    }

    public function test_admin_can_close_the_application_form(): void
    {
        $this->asAdminWeb()->post(route('admin.onboarding.toggle'), ['enabled' => 0])->assertSessionHas('status');
        auth()->logout();
        $this->get(route('salon-application.create'))->assertOk()->assertSee('Başvuru formu şu anda kapalı')->assertDontSee('Başvuruyu gönder');
        $this->post(route('salon-application.store'), $this->applicationData())->assertNotFound();
        $this->assertSame(0, SalonApplication::count());
    }

    public function test_admin_approves_an_application_creating_the_salon_and_inviting_the_owner(): void
    {
        $this->post(route('salon-application.store'), $this->applicationData());
        $a = SalonApplication::sole();

        $this->asAdminWeb()->get(route('admin.onboarding.application', $a->id))->assertOk()->assertSee('Cilt Bakımı')->assertSee('Onayla ve oluştur');
        $this->post(route('admin.onboarding.approve', $a->id), ['plan' => 'standart', 'services' => "Cilt Bakımı:60:900\nAğda:40:400", 'employees' => "Derya\nSeda", 'invite' => 1])
            ->assertRedirect();

        $tenant = Tenant::where('name', 'Papatya Güzellik')->firstOrFail();
        $this->assertSame(SalonApplicationStatus::Approved, $a->refresh()->status);
        $this->assertSame($tenant->id, $a->tenant_id);
        $this->assertTrue($tenant->public_listing_enabled);
        $this->assertSame('gursu', DB::table('districts')->where('id', DB::table('branches')->where('tenant_id', $tenant->id)->value('district_id'))->value('slug'));
        $this->assertSame('epilasyon', DB::table('service_categories')->where('id', DB::table('services')->where('tenant_id', $tenant->id)->where('name', 'Ağda')->value('category_id'))->value('slug'));
        Notification::assertSentTo(User::findOrFail($tenant->owner_user_id), SalonInvitation::class);

        $this->post(route('admin.onboarding.approve', $a->id), ['plan' => 'standart'])->assertStatus(409);
    }

    public function test_bad_service_lines_keep_the_application_pending(): void
    {
        $this->post(route('salon-application.store'), $this->applicationData());
        $a = SalonApplication::sole();
        $this->asAdminWeb()->post(route('admin.onboarding.approve', $a->id), ['plan' => 'standart', 'services' => 'Cilt Bakımı:altmış:900'])->assertSessionHasErrors('services');
        $this->assertSame(SalonApplicationStatus::Pending, $a->refresh()->status);
        $this->assertSame(0, Tenant::count());
    }

    public function test_admin_rejects_an_application_and_notifies_the_applicant(): void
    {
        $this->post(route('salon-application.store'), $this->applicationData());
        $a = SalonApplication::sole();
        $this->asAdminWeb()->post(route('admin.onboarding.reject', $a->id), ['reason' => 'Adres doğrulanamadı.', 'notify' => 1])
            ->assertRedirect(route('admin.onboarding.applications'));

        $this->assertSame(SalonApplicationStatus::Rejected, $a->refresh()->status);
        Notification::assertSentOnDemand(SalonApplicationRejected::class, fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === 'derya@papatya.test');
        $this->get(route('admin.onboarding.applications', ['durum' => 'rejected']))->assertSee('Papatya Güzellik');
    }

    public function test_invitation_survives_the_password_reset_token_cleanup(): void
    {
        $this->asAdminWeb()->post(route('admin.onboarding.run'), ['token' => $this->previewToken(), 'invite' => 1]);
        $owner = User::where('email', 'ayse@isik.test')->firstOrFail();
        $token = null;
        Notification::assertSentTo($owner, SalonInvitation::class, function (SalonInvitation $n) use (&$token) {
            $token = $n->token;

            return true;
        });
        auth()->logout();

        $this->travel(2)->hours();
        $this->artisan('auth:clear-resets')->assertSuccessful(); // 60-minute password resets only
        $this->post(route('invite.accept'), ['token' => $token, 'email' => $owner->email, 'password' => 'YeniSifre123', 'password_confirmation' => 'YeniSifre123'])
            ->assertRedirect(route('login'))->assertSessionHasNoErrors();
    }
}
