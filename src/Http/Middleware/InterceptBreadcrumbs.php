<?php

namespace Elsed115\Breadcrumbs\Http\Middleware;

use Closure;

use Illuminate\View\View;
use Illuminate\Http\Request;

use Symfony\Component\HttpFoundation\Response;

use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

use Laravel\Nova\Nova;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Http\Requests\ResourceDetailRequest;

use Elsed115\Breadcrumbs\Breadcrumbs;

class InterceptBreadcrumbs {

    public function handle(Request $request, Closure $next) {

        if (array_key_exists("uses", $request->route()->action) && $request->route()->action['uses'] instanceof Closure) {
            return $next($request);
        }

        $routeController = $request->route()->getController();
        // Skip breadcrumb middleware for create/attach Nova requests
        // Convert to NovaRequest and skip for create or attach pages
        $novaRequest = $request instanceof NovaRequest
            ? $request
            : NovaRequest::createFrom($request);
        $path = $request->getPathInfo();
        if ($novaRequest->isCreateOrAttachRequest() || strpos($path, '/attach/') !== false) {
            return $next($request);
        }

        if ( $this->isPageController($routeController) && Nova::breadcrumbsEnabled()) {
            $request = NovaRequest::createFrom($request);
            $response = $next($request);

            if ($response->original instanceof View) {
                $responseData = $response->original->getData();
                $responsePage = $responseData['page'];
            }
            else {
                $responsePage = $response->original;
            }

            if (is_null($responsePage) || !is_array($responsePage)) {
                return $response;
            }

            $responsePage['props'] ??= [];
            // Get breadcrumbs as array and remove default resource index crumb (path starting with /resources/)
            $breadcrumbsList = $this->getBreadcrumbs($request);
            if (method_exists($breadcrumbsList, 'toArray')) {
                $breadcrumbsArray = $breadcrumbsList->toArray();
            } elseif ($breadcrumbsList instanceof \Traversable) {
                $breadcrumbsArray = iterator_to_array($breadcrumbsList, false);
            } elseif (is_array($breadcrumbsList)) {
                $breadcrumbsArray = $breadcrumbsList;
            } else {
                $breadcrumbsArray = [$breadcrumbsList];
            }
            // Filter crumbs: keep Home (path '/') and any not linking to resource index
            $filtered = array_values(array_filter($breadcrumbsArray, function($crumb) {
                $path = is_array($crumb) ? ($crumb['path'] ?? null) : ($crumb->path ?? null);
                return $path === '/' || ! str_starts_with($path, '/resources/');
            }));
            $responsePage['props']['breadcrumbs'] = $filtered;

            return Inertia::render($responsePage['component'], $responsePage['props']);
        } else {
            return $next($request);
        }
    }

    protected function getBreadcrumbs(NovaRequest $request) {
        $breadcrumbs = Breadcrumbs::make(null)->build($request);
        return $breadcrumbs;
    }

    protected function isPageController($controller) {
        return ((new \ReflectionClass($controller))?->getNamespaceName() ?? false) === "Laravel\Nova\Http\Controllers\Pages";
    }
}
