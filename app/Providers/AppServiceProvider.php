<?php

namespace App\Providers;

use App\Models\Camera;
use App\Models\Elderly;
use App\Models\ElderlyStay;
use App\Models\Room;
use App\Models\ServiceClass;
use App\Models\ServiceEnrollment;
use App\Models\User;
use App\Policies\CameraPolicy;
use App\Policies\ElderlyPolicy;
use App\Policies\ElderlyStayPolicy;
use App\Policies\RoomPolicy;
use App\Policies\ServiceClassPolicy;
use App\Policies\ServiceEnrollmentPolicy;
use App\Policies\UserPolicy;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Camera::class, CameraPolicy::class);
        Gate::policy(Elderly::class, ElderlyPolicy::class);
        Gate::policy(ElderlyStay::class, ElderlyStayPolicy::class);
        Gate::policy(ServiceEnrollment::class, ServiceEnrollmentPolicy::class);
        Gate::policy(ServiceClass::class, ServiceClassPolicy::class);
        Gate::policy(Room::class, RoomPolicy::class);
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(30)->by('login|'.$request->ip()));
        RateLimiter::for('admin-password-reset', fn (Request $request) => Limit::perMinute(6)->by('reset|'.$request->user()->id));
    }
}
