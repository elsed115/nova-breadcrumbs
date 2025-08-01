<?php

namespace Elsed115\Breadcrumbs;

use Elsed115\Breadcrumbs\Http\Middleware\InterceptBreadcrumbs;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Nova\Events\ServingNova;
use Laravel\Nova\Nova;
use Illuminate\Support\Facades\Log;
use Laravel\Nova\Http\Requests\NovaRequest;

class BreadcrumbServiceProvider extends ServiceProvider {
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot() {

        $this->addMiddleware();

        $this->publishes([
            __DIR__.'/../config/nova-breadcrumbs.php' => config_path('nova-breadcrumbs.php'),
        ], "nova-breadcrumbs-config");

        // Default resource breadcrumbs callback: supports viaBreadcrumbs chain in URL
        Breadcrumbs::resourceCallback(function(NovaRequest $request, Breadcrumbs $breadcrumbs, array $items) {
            $new = [];
            $viaBreadcrumbs = [];
            Log::info('Nova breadcrumbs callback', [
                'request' => $request->all(),
                'items' => $items,
            ]);

            // 1. Check if viaBreadcrumbs chain passed in URL
            if ($request->has('viaBreadcrumbs')) {
                $viaBreadcrumbs = json_decode(base64_decode($request->query('viaBreadcrumbs')), true) ?? [];
                // 2. Build each breadcrumb from the chain
                foreach ($viaBreadcrumbs as $crumb) {
                    $new[] = Breadcrumb::make($crumb['title'], $crumb['url']);
                }
            }

            // 3. If no viaBreadcrumbs and default items >1, add the index link (penultimate default crumb)
            if (empty($viaBreadcrumbs) && count($items) > 1) {
                $new[] = $items[count($items) - 2];
            }

            // 4. Append the current resource crumb (e.g. resource name)
            $new[] = end($items);

            return $new;
        });
        // Default index callback: include parent resource for HasMany relationships
        Breadcrumbs::indexCallback(function(NovaRequest $request, Breadcrumbs $breadcrumbs, $crumb) {
            // If accessing via a relationship (e.g., HasMany)
            if ($relation = $request->viaRelationship()) {
                // Parent Nova resource instance
                $parentResource = $request->findParentResource();
                return [
                    Breadcrumb::make(__('Home'), '/'),
                    // Parent resource breadcrumb
                    Breadcrumb::resource($parentResource),
                    $crumb,
                ];
            }
            return [$crumb];
        });
        // Detail callback: include parent resource when accessing via HasMany relationship
        Breadcrumbs::detailCallback(function(NovaRequest $request, Breadcrumbs $breadcrumbs, $crumb) {
            if ($relation = $request->viaRelationship()) {
                $parent = $request->findParentResource();
                return [
                    Breadcrumb::make(__('Home'), '/'),
                    Breadcrumb::resource($parent),
                    $crumb,
                ];
            }
            return [$crumb];
        });
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register() {
     //
    }

    public function addMiddleware()
    {
        $router = $this->app['router'];

        if ($router->hasMiddlewareGroup('nova')) {
            $router->pushMiddlewareToGroup('nova', InterceptBreadcrumbs::class);
            return;
        }

        if (! $this->app->configurationIsCached()) {
            config()->set('nova.middleware', array_merge(
                config('nova.middleware', []),
                [InterceptBreadcrumbs::class]
            ));
        }
    }
}
