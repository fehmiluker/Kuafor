<?php

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Customer\MeController;
use App\Http\Controllers\Api\V1\Public\BookingController;
use App\Http\Controllers\Api\V1\Public\CatalogController;
use App\Http\Controllers\Api\V1\Tenant\AppointmentController;
use App\Http\Controllers\Api\V1\Tenant\BranchController;
use App\Http\Controllers\Api\V1\Tenant\CustomerController;
use App\Http\Controllers\Api\V1\Tenant\DashboardController;
use App\Http\Controllers\Api\V1\Tenant\EmployeeController;
use App\Http\Controllers\Api\V1\Tenant\ReviewController;
use App\Http\Controllers\Api\V1\Tenant\ServiceController;
use App\Http\Controllers\Api\V1\Tenant\SettingsController;
use App\Http\Controllers\Api\V1\Tenant\SubscriptionController;
use App\Http\Controllers\Api\V1\Tenant\WhatsAppController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    // ---------------------------------------------------------------- public
    Route::get('cities', [CatalogController::class, 'cities'])->name('cities');
    Route::get('districts', [CatalogController::class, 'districts'])->name('districts');
    Route::get('categories', [CatalogController::class, 'categories'])->name('categories');
    Route::get('salons', [CatalogController::class, 'salons'])->name('salons.index');
    Route::get('salons/{slug}', [CatalogController::class, 'salon'])->name('salons.show');
    Route::get('salons/{slug}/branches', [CatalogController::class, 'branches'])->name('salons.branches');
    Route::get('salons/{slug}/reviews', [CatalogController::class, 'reviews'])->name('salons.reviews');
    Route::get('branches/{branch}/services', [CatalogController::class, 'services'])->whereNumber('branch')->name('branches.services');
    Route::get('branches/{branch}/employees', [CatalogController::class, 'employees'])->whereNumber('branch')->name('branches.employees');
    Route::get('availability', [CatalogController::class, 'availability'])->name('availability');

    Route::post('appointments', [BookingController::class, 'store'])->middleware(['throttle:booking', 'idempotent'])->name('appointments.store');
    Route::post('booking/verify-phone', [AuthController::class, 'sendBookingCode'])->middleware('throttle:otp')->name('booking.verify-phone');

    Route::middleware('throttle:guest')->group(function () {
        Route::get('guest/appointments/{reference}', [BookingController::class, 'show'])->name('guest.show');
        Route::post('guest/appointments/{reference}/cancel', [BookingController::class, 'cancel'])->name('guest.cancel');
        Route::post('guest/proposals/{proposal}/accept', [BookingController::class, 'acceptProposal'])->whereNumber('proposal')->name('guest.proposals.accept');
        Route::post('guest/proposals/{proposal}/reject', [BookingController::class, 'rejectProposal'])->whereNumber('proposal')->name('guest.proposals.reject');
    });

    // ---------------------------------------------------------------- auth
    Route::middleware('throttle:auth')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
        Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->name('auth.forgot');
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('auth/verify-phone', [AuthController::class, 'verifyPhone'])->middleware('throttle:otp')->name('auth.verify-phone');

        // ------------------------------------------------------------ customer
        Route::get('me', [MeController::class, 'show'])->name('me.show');
        Route::patch('me', [MeController::class, 'update'])->name('me.update');
        Route::get('me/appointments', [MeController::class, 'appointments'])->name('me.appointments');
        Route::post('me/appointments/{id}/cancel', [MeController::class, 'cancel'])->whereNumber('id')->name('me.appointments.cancel');
        Route::post('me/appointments/{id}/reschedule-request', [MeController::class, 'rescheduleRequest'])->whereNumber('id')->name('me.appointments.reschedule');
        Route::get('me/favorites', [MeController::class, 'favorites'])->name('me.favorites');
        Route::post('me/favorites', [MeController::class, 'addFavorite'])->name('me.favorites.store');
        Route::delete('me/favorites/{slug}', [MeController::class, 'removeFavorite'])->name('me.favorites.destroy');
        Route::get('me/reviews', [MeController::class, 'reviews'])->name('me.reviews');
        Route::post('me/data-requests', [MeController::class, 'dataRequest'])->name('me.data-requests');
        Route::post('appointments/{id}/reviews', [MeController::class, 'storeReview'])->whereNumber('id')->name('appointments.reviews.store');

        // ------------------------------------------------------------ tenant
        Route::prefix('tenant')->name('tenant.')->middleware(['tenant', 'subscribed', 'idempotent'])->group(function () {
            Route::get('dashboard', [DashboardController::class, 'dashboard'])->name('dashboard');
            Route::get('reports', [DashboardController::class, 'reports'])->middleware('feature:reports')->name('reports');
            Route::get('settings', [SettingsController::class, 'show'])->name('settings');
            Route::patch('settings', [SettingsController::class, 'update'])->name('settings.update');
            Route::get('booking-settings', [SettingsController::class, 'booking'])->name('booking-settings');
            Route::patch('booking-settings', [SettingsController::class, 'updateBooking'])->name('booking-settings.update');

            Route::apiResource('branches', BranchController::class);
            Route::apiResource('employees', EmployeeController::class);
            Route::apiResource('services', ServiceController::class);
            Route::apiResource('customers', CustomerController::class);

            Route::get('appointments', [AppointmentController::class, 'index'])->name('appointments.index');
            Route::post('appointments', [AppointmentController::class, 'store'])->name('appointments.store');
            Route::get('appointments/{appointment}', [AppointmentController::class, 'show'])->name('appointments.show');
            Route::post('appointments/{appointment}/approve', [AppointmentController::class, 'approve'])->name('appointments.approve');
            Route::post('appointments/{appointment}/reject', [AppointmentController::class, 'reject'])->name('appointments.reject');
            Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])->name('appointments.cancel');
            Route::post('appointments/{appointment}/complete', [AppointmentController::class, 'complete'])->name('appointments.complete');
            Route::post('appointments/{appointment}/no-show', [AppointmentController::class, 'noShow'])->name('appointments.no-show');
            Route::post('appointments/{appointment}/proposals', [AppointmentController::class, 'propose'])->name('appointments.proposals');
            Route::post('appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])->name('appointments.reschedule');
            Route::post('appointments/{appointment}/payments', [AppointmentController::class, 'recordPayment'])->name('appointments.payments');

            Route::get('subscription', [SubscriptionController::class, 'show'])->name('subscription');
            Route::post('subscription/change-plan', [SubscriptionController::class, 'changePlan'])->withoutMiddleware('subscribed')->name('subscription.change-plan');

            Route::middleware('feature:whatsapp')->group(function () {
                Route::get('whatsapp/connections', [WhatsAppController::class, 'index'])->name('whatsapp.index');
                Route::post('branches/{branch}/whatsapp/connect', [WhatsAppController::class, 'connect'])->name('whatsapp.connect');
                Route::post('branches/{branch}/whatsapp/disconnect', [WhatsAppController::class, 'disconnect'])->name('whatsapp.disconnect');
                Route::get('branches/{branch}/whatsapp/status', [WhatsAppController::class, 'status'])->name('whatsapp.status');
            });

            Route::get('reviews', [ReviewController::class, 'index'])->name('reviews.index');
            Route::post('reviews/{review}/reply', [ReviewController::class, 'reply'])->name('reviews.reply');
            Route::post('reviews/{review}/report', [ReviewController::class, 'report'])->name('reviews.report');
        });

        // ------------------------------------------------------------ super admin
        Route::prefix('admin')->name('admin.')->middleware('platform')->group(function () {
            Route::get('dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
            Route::get('tenants', [AdminController::class, 'tenants'])->name('tenants.index');
            Route::post('tenants', [AdminController::class, 'storeTenant'])->name('tenants.store');
            Route::get('tenants/{tenant:id}', [AdminController::class, 'showTenant'])->name('tenants.show');
            Route::patch('tenants/{tenant:id}', [AdminController::class, 'updateTenant'])->name('tenants.update');
            Route::delete('tenants/{tenant:id}', [AdminController::class, 'destroyTenant'])->name('tenants.destroy');
            Route::get('tenants/{tenant:id}/customers', [AdminController::class, 'tenantCustomers'])->name('tenants.customers');
            Route::get('plans', [AdminController::class, 'plans'])->name('plans.index');
            Route::post('plans', [AdminController::class, 'storePlan'])->name('plans.store');
            Route::get('plans/{plan}', [AdminController::class, 'showPlan'])->name('plans.show');
            Route::patch('plans/{plan}', [AdminController::class, 'updatePlan'])->name('plans.update');
            Route::delete('plans/{plan}', [AdminController::class, 'destroyPlan'])->name('plans.destroy');
            Route::get('subscriptions/{subscription}', [AdminController::class, 'showSubscription'])->name('subscriptions.show');
            Route::patch('subscriptions/{subscription}', [AdminController::class, 'updateSubscription'])->name('subscriptions.update');
            Route::get('whatsapp/health', [AdminController::class, 'whatsappHealth'])->name('whatsapp.health');
            Route::get('review-reports', [AdminController::class, 'reviewReports'])->name('review-reports');
            Route::post('reviews/{review}/moderate', [AdminController::class, 'moderate'])->whereNumber('review')->name('reviews.moderate');
            Route::get('audit-logs', [AdminController::class, 'auditLogs'])->name('audit-logs');
        });
    });
});
