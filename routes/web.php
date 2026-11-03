<?php

use App\Http\Controllers\Web\Admin\AdminAdController;
use App\Http\Controllers\Web\Admin\AdminOnboardingController;
use App\Http\Controllers\Web\Admin\AdminPanelController;
use App\Http\Controllers\Web\Admin\AdminSecurityController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\Customer\AccountController;
use App\Http\Controllers\Web\Public\AdClickController;
use App\Http\Controllers\Web\Public\BookingLinkController;
use App\Http\Controllers\Web\Public\GuestAppointmentController;
use App\Http\Controllers\Web\Public\LocationController;
use App\Http\Controllers\Web\Public\PublicController;
use App\Http\Controllers\Web\Public\SalonApplicationController;
use App\Http\Controllers\Web\Tenant\PanelAppointmentController;
use App\Http\Controllers\Web\Tenant\PanelCatalogController;
use App\Http\Controllers\Web\Tenant\PanelController;
use App\Http\Controllers\Web\Tenant\PanelShareController;
use App\Http\Controllers\Web\Tenant\SubscriptionPaymentController;
use App\Http\Controllers\Web\TwoFactorController;
use App\Livewire\Public\BookingWizard;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------- public
Route::get('/', [PublicController::class, 'home'])->name('home');
Route::get('salonlar/{city?}/{district?}', [PublicController::class, 'search'])->name('search');
Route::get('salon/{slug}', [PublicController::class, 'salon'])->name('salon.show');
Route::get('salon/{slug}/randevu', BookingWizard::class)->name('salon.book');
Route::get('r/{slug}', BookingLinkController::class)->middleware(['throttle:60,1', 'noindex'])->name('booking-link');
Route::get('konum', [LocationController::class, 'edit'])->middleware('noindex')->name('location.edit');
Route::post('konum', [LocationController::class, 'update'])->middleware('throttle:30,1')->name('location.update');
Route::get('reklam/{ad}', AdClickController::class)->whereNumber('ad')->middleware(['throttle:60,1', 'noindex'])->name('ads.click');
Route::get('sitemap.xml', [PublicController::class, 'sitemap'])->name('sitemap');
Route::get('robots.txt', [PublicController::class, 'robots'])->name('robots');
Route::view('kvkk-aydinlatma', 'public.kvkk')->name('kvkk');
Route::view('kullanim-kosullari', 'public.terms')->name('terms');
Route::get('salonunu-ekle', [SalonApplicationController::class, 'create'])->name('salon-application.create');
Route::post('salonunu-ekle', [SalonApplicationController::class, 'store'])->middleware('throttle:5,60')->name('salon-application.store');

Route::middleware(['noindex', 'throttle:guest'])->group(function () {
    Route::get('randevu/{reference}', [GuestAppointmentController::class, 'show'])->name('guest.appointment');
    Route::post('randevu/{reference}/iptal', [GuestAppointmentController::class, 'cancel'])->name('guest.cancel');
    Route::get('randevu/{reference}/degerlendir', [GuestAppointmentController::class, 'reviewForm'])->name('guest.review');
    Route::post('randevu/{reference}/degerlendir', [GuestAppointmentController::class, 'storeReview'])->name('guest.review.store');
    Route::get('teklif/{proposal}', [GuestAppointmentController::class, 'proposal'])->whereNumber('proposal')->name('proposal.show');
    Route::post('teklif/{proposal}/{decision}', [GuestAppointmentController::class, 'respond'])->whereNumber('proposal')->whereIn('decision', ['kabul', 'red'])->name('proposal.respond');
});

// ---------------------------------------------------------------- auth
Route::middleware('guest')->group(function () {
    Route::get('giris', [AuthController::class, 'showLogin'])->name('login');
    Route::post('giris', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::get('kayit', [AuthController::class, 'showRegister'])->name('register');
    Route::post('kayit', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::get('sifremi-unuttum', [AuthController::class, 'showForgot'])->name('password.request');
    Route::post('sifremi-unuttum', [AuthController::class, 'forgot'])->middleware('throttle:auth')->name('password.email');
    Route::get('sifre-sifirla/{token}', [AuthController::class, 'showReset'])->name('password.reset');
    Route::post('sifre-sifirla', [AuthController::class, 'reset'])->middleware('throttle:auth')->name('password.update');
    Route::get('davet/{token}', [AuthController::class, 'showInvite'])->middleware('noindex')->name('invite.show');
    Route::post('davet', [AuthController::class, 'acceptInvite'])->middleware('throttle:auth')->name('invite.accept');
});

Route::middleware(['auth', 'noindex'])->group(function () {
    Route::post('cikis', [AuthController::class, 'logout'])->name('logout');
    Route::get('iki-adimli/kurulum', [TwoFactorController::class, 'setup'])->name('two-factor.setup');
    Route::post('iki-adimli/kurulum', [TwoFactorController::class, 'confirm'])->middleware('throttle:otp')->name('two-factor.confirm');
    Route::get('iki-adimli', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('iki-adimli', [TwoFactorController::class, 'verify'])->middleware('throttle:otp')->name('two-factor.verify');

    // ------------------------------------------------------------ customer
    Route::prefix('hesabim')->name('account.')->group(function () {
        Route::get('/', [AccountController::class, 'appointments'])->name('appointments');
        Route::post('randevu/{id}/iptal', [AccountController::class, 'cancel'])->whereNumber('id')->name('appointments.cancel');
        Route::get('favoriler', [AccountController::class, 'favorites'])->name('favorites');
        Route::post('favoriler/{slug}', [AccountController::class, 'toggleFavorite'])->name('favorites.toggle');
        Route::get('degerlendirmeler', [AccountController::class, 'reviews'])->name('reviews');
        Route::get('profil', [AccountController::class, 'profile'])->name('profile');
        Route::post('profil', [AccountController::class, 'updateProfile'])->name('profile.update');
        Route::post('telefon-dogrula', [AccountController::class, 'verifyPhone'])->middleware('throttle:otp')->name('phone.verify');
        Route::post('veri-talebi', [AccountController::class, 'dataRequest'])->name('data-request');
        Route::get('veri-indir', [AccountController::class, 'export'])->name('export');
    });

    // ------------------------------------------------------------ tenant panel
    Route::get('panel/isletme-sec', [PanelController::class, 'selectTenant'])->name('tenant.select');
    Route::post('panel/isletme-sec', [PanelController::class, 'switchTenant'])->name('tenant.switch');

    Route::prefix('panel')->name('tenant.')->middleware(['tenant', 'subscribed'])->group(function () {
        Route::get('/', [PanelController::class, 'dashboard'])->name('dashboard');
        Route::get('takvim', [PanelController::class, 'calendar'])->name('calendar');
        Route::get('takvim/olaylar', [PanelAppointmentController::class, 'events'])->name('calendar.events');
        Route::get('bildirimler', [PanelController::class, 'notifications'])->name('notifications');
        Route::post('bildirimler/okundu', [PanelController::class, 'markNotificationsRead'])->name('notifications.read');

        Route::get('randevular', [PanelAppointmentController::class, 'index'])->name('appointments.index');
        Route::get('randevular/yeni', [PanelAppointmentController::class, 'create'])->name('appointments.create');
        Route::post('randevular', [PanelAppointmentController::class, 'store'])->name('appointments.store');
        // Reachable from signed e-mail links too; login + permission still required.
        Route::get('randevular/{appointment}', [PanelAppointmentController::class, 'show'])->name('appointments.show');
        Route::post('randevular/{appointment}/{action}', [PanelAppointmentController::class, 'act'])
            ->whereIn('action', ['onayla', 'reddet', 'iptal', 'tamamla', 'gelmedi', 'oner', 'tasi', 'tahsilat'])->name('appointments.act');
        Route::post('randevular/{appointment}/surukle', [PanelAppointmentController::class, 'drag'])->name('appointments.drag');

        Route::get('subeler', [PanelCatalogController::class, 'branches'])->name('branches.index');
        Route::get('subeler/yeni', [PanelCatalogController::class, 'branchForm'])->name('branches.create');
        Route::get('subeler/{branch}', [PanelCatalogController::class, 'branchForm'])->name('branches.edit');
        Route::post('subeler', [PanelCatalogController::class, 'saveBranch'])->name('branches.store');
        Route::put('subeler/{branch}', [PanelCatalogController::class, 'saveBranch'])->name('branches.update');
        Route::delete('subeler/{branch}', [PanelCatalogController::class, 'deleteBranch'])->name('branches.destroy');
        Route::post('subeler/{branch}/kapali-zaman', [PanelCatalogController::class, 'addBlockedTime'])->name('branches.blocked');
        Route::delete('subeler/{branch}/kapali-zaman/{blocked}', [PanelCatalogController::class, 'removeBlockedTime'])->whereNumber('blocked')->name('branches.blocked.destroy');
        Route::get('subeler/{branch}/whatsapp', [PanelCatalogController::class, 'whatsapp'])->middleware('feature:whatsapp')->name('branches.whatsapp');
        Route::post('subeler/{branch}/whatsapp', [PanelCatalogController::class, 'whatsappConnect'])->middleware('feature:whatsapp')->name('branches.whatsapp.connect');
        Route::post('subeler/{branch}/whatsapp/embedded', [PanelCatalogController::class, 'whatsappEmbedded'])->middleware('feature:whatsapp')->name('branches.whatsapp.embedded');
        Route::delete('subeler/{branch}/whatsapp', [PanelCatalogController::class, 'whatsappDisconnect'])->middleware('feature:whatsapp')->name('branches.whatsapp.disconnect');

        Route::get('personel', [PanelCatalogController::class, 'employees'])->name('employees.index');
        Route::get('personel/yeni', [PanelCatalogController::class, 'employeeForm'])->name('employees.create');
        Route::get('personel/{employee}', [PanelCatalogController::class, 'employeeForm'])->name('employees.edit');
        Route::post('personel', [PanelCatalogController::class, 'saveEmployee'])->name('employees.store');
        Route::put('personel/{employee}', [PanelCatalogController::class, 'saveEmployee'])->name('employees.update');
        Route::delete('personel/{employee}', [PanelCatalogController::class, 'deleteEmployee'])->name('employees.destroy');
        Route::post('personel/{employee}/izin', [PanelCatalogController::class, 'addLeave'])->name('employees.leave');

        Route::get('hizmetler', [PanelCatalogController::class, 'services'])->name('services.index');
        Route::get('hizmetler/yeni', [PanelCatalogController::class, 'serviceForm'])->name('services.create');
        Route::get('hizmetler/{service}', [PanelCatalogController::class, 'serviceForm'])->name('services.edit');
        Route::post('hizmetler', [PanelCatalogController::class, 'saveService'])->name('services.store');
        Route::put('hizmetler/{service}', [PanelCatalogController::class, 'saveService'])->name('services.update');
        Route::delete('hizmetler/{service}', [PanelCatalogController::class, 'deleteService'])->name('services.destroy');

        Route::get('musteriler', [PanelCatalogController::class, 'customers'])->name('customers.index');
        Route::get('musteriler/{customer}', [PanelCatalogController::class, 'customer'])->name('customers.show');
        Route::put('musteriler/{customer}', [PanelCatalogController::class, 'updateCustomer'])->name('customers.update');

        Route::get('baglanti', [PanelShareController::class, 'show'])->name('share');
        Route::get('baglanti/qr', [PanelShareController::class, 'qr'])->name('share.qr');
        Route::get('baglanti/yazdir', [PanelShareController::class, 'print'])->name('share.print');

        Route::get('degerlendirmeler', [PanelController::class, 'reviews'])->name('reviews');
        Route::post('degerlendirmeler/{review}/yanit', [PanelController::class, 'replyReview'])->name('reviews.reply');
        Route::post('degerlendirmeler/{review}/bildir', [PanelController::class, 'reportReview'])->name('reviews.report');

        Route::get('raporlar', [PanelController::class, 'reports'])->middleware('feature:reports')->name('reports');
        Route::get('ayarlar', [PanelController::class, 'settings'])->name('settings');
        Route::post('ayarlar', [PanelController::class, 'saveSettings'])->name('settings.save');
        Route::post('ayarlar/medya', [PanelController::class, 'uploadMedia'])->name('settings.media');
        Route::get('abonelik', [PanelController::class, 'subscription'])->withoutMiddleware('subscribed')->name('subscription');
        Route::post('abonelik', [PanelController::class, 'changePlan'])->withoutMiddleware('subscribed')->name('subscription.change');
        Route::post('abonelik/odeme', [SubscriptionPaymentController::class, 'start'])->withoutMiddleware('subscribed')->middleware('throttle:10,1')->name('subscription.pay');
        Route::get('abonelik/odeme/{oid}', [SubscriptionPaymentController::class, 'show'])->withoutMiddleware('subscribed')->where('oid', '[A-Za-z0-9]+')->name('subscription.pay.show');
    });

    // ------------------------------------------------------------ super admin
    Route::prefix('admin')->name('admin.')->middleware('platform')->group(function () {
        Route::get('/', [AdminPanelController::class, 'dashboard'])->name('dashboard');
        Route::get('guvenlik', [AdminSecurityController::class, 'edit'])->name('security');
        Route::put('guvenlik/parola', [AdminSecurityController::class, 'update'])->middleware('throttle:5,1')->name('security.password');
        Route::get('isletmeler', [AdminPanelController::class, 'tenants'])->name('tenants');
        Route::get('isletmeler/yeni', [AdminOnboardingController::class, 'createForm'])->name('tenants.create');
        Route::post('isletmeler', [AdminOnboardingController::class, 'createSalon'])->name('tenants.store');
        Route::get('isletmeler/{tenant:id}', [AdminPanelController::class, 'tenant'])->name('tenants.show');
        Route::post('isletmeler/{tenant:id}', [AdminPanelController::class, 'updateTenant'])->name('tenants.update');
        Route::post('abonelikler/{subscription}', [AdminPanelController::class, 'updateSubscription'])->name('subscriptions.update');
        Route::get('paketler', [AdminPanelController::class, 'plans'])->name('plans');
        Route::post('paketler', [AdminPanelController::class, 'savePlan'])->name('plans.store');
        Route::put('paketler/{plan}', [AdminPanelController::class, 'savePlan'])->name('plans.update');
        Route::get('moderasyon', [AdminPanelController::class, 'moderation'])->name('moderation');
        Route::post('moderasyon/{review}', [AdminPanelController::class, 'moderate'])->whereNumber('review')->name('moderate');
        Route::get('denetim', [AdminPanelController::class, 'audit'])->name('audit');
        Route::get('sistem', [AdminPanelController::class, 'system'])->name('system');
        Route::get('odeme-ayarlari', [\App\Http\Controllers\Web\Admin\AdminPaymentController::class, 'edit'])->name('payments');
        Route::put('odeme-ayarlari', [\App\Http\Controllers\Web\Admin\AdminPaymentController::class, 'update'])->name('payments.update');
        Route::get('kategoriler', [\App\Http\Controllers\Web\Admin\AdminContentController::class, 'categories'])->name('categories');
        Route::post('kategoriler', [\App\Http\Controllers\Web\Admin\AdminContentController::class, 'saveCategory'])->name('categories.store');
        Route::put('kategoriler/{category}', [\App\Http\Controllers\Web\Admin\AdminContentController::class, 'saveCategory'])->whereNumber('category')->name('categories.update');
        Route::delete('kategoriler/{category}', [\App\Http\Controllers\Web\Admin\AdminContentController::class, 'deleteCategory'])->whereNumber('category')->name('categories.destroy');
        Route::prefix('icerik/{kind}')->where(['kind' => 'banner|popular'])->group(function () {
            Route::get('/', [\App\Http\Controllers\Web\Admin\AdminContentController::class, 'index'])->name('content.index');
            Route::get('yeni', [\App\Http\Controllers\Web\Admin\AdminContentController::class, 'form'])->name('content.create');
            Route::post('/', [\App\Http\Controllers\Web\Admin\AdminContentController::class, 'save'])->name('content.store');
            Route::get('{item}', [\App\Http\Controllers\Web\Admin\AdminContentController::class, 'form'])->whereNumber('item')->name('content.edit');
            Route::put('{item}', [\App\Http\Controllers\Web\Admin\AdminContentController::class, 'save'])->whereNumber('item')->name('content.update');
            Route::delete('{item}', [\App\Http\Controllers\Web\Admin\AdminContentController::class, 'destroy'])->whereNumber('item')->name('content.destroy');
        });
        Route::get('reklamlar', [AdminAdController::class, 'index'])->name('ads.index');
        Route::get('reklamlar/yeni', [AdminAdController::class, 'create'])->name('ads.create');
        Route::post('reklamlar', [AdminAdController::class, 'store'])->name('ads.store');
        Route::get('reklamlar/{ad}', [AdminAdController::class, 'edit'])->whereNumber('ad')->name('ads.edit');
        Route::put('reklamlar/{ad}', [AdminAdController::class, 'update'])->whereNumber('ad')->name('ads.update');
        Route::delete('reklamlar/{ad}', [AdminAdController::class, 'destroy'])->whereNumber('ad')->name('ads.destroy');
        Route::get('salon-aktarma', [AdminOnboardingController::class, 'importForm'])->name('onboarding.import');
        Route::get('salon-aktarma/sablon', [AdminOnboardingController::class, 'template'])->name('onboarding.template');
        Route::post('salon-aktarma/onizle', [AdminOnboardingController::class, 'preview'])->name('onboarding.preview');
        Route::post('salon-aktarma', [AdminOnboardingController::class, 'import'])->name('onboarding.run');
        Route::get('basvurular', [AdminOnboardingController::class, 'applications'])->name('onboarding.applications');
        Route::post('basvurular/ayar', [AdminOnboardingController::class, 'toggleApplications'])->name('onboarding.toggle');
        Route::get('basvurular/{application}', [AdminOnboardingController::class, 'application'])->whereNumber('application')->name('onboarding.application');
        Route::post('basvurular/{application}/onayla', [AdminOnboardingController::class, 'approve'])->whereNumber('application')->name('onboarding.approve');
        Route::post('basvurular/{application}/reddet', [AdminOnboardingController::class, 'reject'])->whereNumber('application')->name('onboarding.reject');
        Route::post('sistem/whatsapp', [AdminPanelController::class, 'connectPlatformWhatsApp'])->name('whatsapp.platform');
        Route::delete('sistem/whatsapp', [AdminPanelController::class, 'disconnectPlatformWhatsApp'])->name('whatsapp.platform.disconnect');
        Route::post('isletmeler/{tenant:id}/subeler/{branch}/whatsapp', [AdminPanelController::class, 'connectBranchWhatsApp'])->whereNumber('branch')->name('tenants.whatsapp');
    });
});
