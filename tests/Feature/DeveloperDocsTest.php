<?php

use App\Models\Department;
use App\Models\User;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/**
 * Every integration API route as "METHOD /path" relative to /api/integrations/v1,
 * with route parameters normalised to the spec's {reportId} style.
 *
 * @return list<string>
 */
function integrationRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteDefinition $route) => str_starts_with($route->uri(), 'api/integrations/v1/'))
        ->flatMap(fn (RouteDefinition $route) => collect($route->methods())
            ->reject(fn (string $method) => $method === 'HEAD')
            ->map(fn (string $method) => $method.' '.substr($route->uri(), strlen('api/integrations/v1'))))
        ->sort()
        ->values()
        ->all();
}

test('the developer docs are public', function () {
    $this->get('/developers')
        ->assertOk()
        ->assertSee('CivicPulse Integration API')
        ->assertSee(url('/api/integrations/v1'));
});

test('the developer docs describe every integration endpoint', function () {
    $html = $this->get('/developers')->getContent();

    foreach (integrationRoutes() as $route) {
        [$method, $path] = explode(' ', $route);
        $documentedPath = str_replace('{reportId}', '{id}', $path);

        expect($html)->toContain('docs-method--'.strtolower($method).'">'.$method.'</span> '.$documentedPath);
    }
});

test('the OpenAPI spec is valid JSON and covers exactly the integration routes', function () {
    $spec = json_decode(file_get_contents(public_path('openapi/integrations-v1.json')), true, flags: JSON_THROW_ON_ERROR);

    $documented = collect($spec['paths'])
        ->flatMap(fn (array $operations, string $path) => collect(array_keys($operations))
            ->map(fn (string $method) => strtoupper($method).' '.$path))
        ->sort()
        ->values()
        ->all();

    expect($spec['openapi'])->toStartWith('3.');
    expect($documented)->toBe(integrationRoutes());
});

test('the departments page links admins to the docs', function () {
    $admin = User::factory()->admin()->create();
    Department::factory()->create();

    $this->actingAs($admin)->get('/departments')->assertSee(route('developers'));
});
