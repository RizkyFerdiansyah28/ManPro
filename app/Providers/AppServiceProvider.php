<?php

namespace App\Providers;

use App\Models\Camera;
use App\Models\Elderly;
use App\Models\ElderlyStay;
use App\Models\FallIncident;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Room;
use App\Models\ServiceClass;
use App\Models\ServiceEnrollment;
use App\Models\User;
use App\Policies\CameraPolicy;
use App\Policies\ElderlyPolicy;
use App\Policies\ElderlyStayPolicy;
use App\Policies\FallIncidentPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\NotificationPolicy;
use App\Policies\RoomPolicy;
use App\Policies\ServiceClassPolicy;
use App\Policies\ServiceEnrollmentPolicy;
use App\Policies\UserPolicy;
use App\Services\Billing\LocalPaymentProofStore;
use App\Services\Billing\PaymentProofStore;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentProofStore::class, LocalPaymentProofStore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (['read' => 60, 'context' => 30, 'submit' => 5, 'review' => 10, 'reconcile' => 10, 'proof' => 20] as $operation => $limit) {
            RateLimiter::for('payment-'.$operation, fn (Request $request) => Limit::perMinute($limit)
                ->by('payment|'.$operation.'|'.$request->user()?->id.'|'.$request->route('invoice').'|'.$request->route('payment')));
        }
        Gate::define('viewFamilyPortal', fn (User $user) => $user->is_active && $user->role === 'keluarga');
        Gate::define('viewMonitoring', fn (User $user) => $user->is_active && in_array($user->role, ['admin', 'perawat'], true));
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Notification::class, NotificationPolicy::class);
        Gate::define('viewNotifications', fn (User $user) => (new NotificationPolicy)->viewAny($user));
        Gate::policy(FallIncident::class, FallIncidentPolicy::class);
        Gate::policy(Camera::class, CameraPolicy::class);
        Gate::policy(Elderly::class, ElderlyPolicy::class);
        Gate::policy(ElderlyStay::class, ElderlyStayPolicy::class);
        Gate::policy(ServiceEnrollment::class, ServiceEnrollmentPolicy::class);
        Gate::policy(ServiceClass::class, ServiceClassPolicy::class);
        Gate::policy(Room::class, RoomPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        foreach (['read' => 30, 'preview' => 12, 'issue' => 6, 'reconcile' => 12] as $operation => $limit) {
            RateLimiter::for('invoice-'.$operation, fn (Request $request) => Limit::perMinute($limit)
                ->by('invoice|'.$operation.'|'.$request->user()->id));
        }
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(30)->by('login|'.$request->ip()));
        RateLimiter::for('notification-summary', fn (Request $request) => Limit::perMinute(60)->by('notifications|'.$request->user()->id));
        RateLimiter::for('admin-password-reset', fn (Request $request) => Limit::perMinute(6)->by('reset|'.$request->user()->id));
    }
}
