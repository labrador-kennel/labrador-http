<?php

/**
 *
 * @license See LICENSE in source root
 * @version 1.0
 * @since   1.0
 */

namespace Cspray\Labrador\Http\Test\Router;

use Cspray\Labrador\Http\HandlerResolver\HandlerResolver;
use Cspray\Labrador\Http\Router\ResolvedRoute;
use Cspray\Labrador\Http\Router\FastRouteRouter;
use Cspray\Labrador\Http\Router\Route;
use Cspray\Labrador\Http\Exception\InvalidHandlerException;
use Cspray\Labrador\Http\Exception\InvalidTypeException;
use FastRoute\DataGenerator\GroupCountBased as GcbDataGenerator;
use FastRoute\Dispatcher\GroupCountBased as GcbDispatcher;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std as StdRouteParser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use PHPUnit_Framework_TestCase as UnitTestCase;

class FastRouteRouterTest extends UnitTestCase {

    private $mockResolver;

    private function getRouter() {
        $this->mockResolver = $this->getMock(HandlerResolver::class);
        return new FastRouteRouter(
            $this->mockResolver,
            new RouteCollector(new StdRouteParser(), new GcbDataGenerator()),
            function($data) { return new GcbDispatcher($data); }
        );
    }

    public function testFastRouteDispatcherCallbackReturnsImproperTypeThrowsException() {
        $mockResolver = $this->getMock(HandlerResolver::class);
        $router = new FastRouteRouter(
            $mockResolver,
            new RouteCollector(new StdRouteParser(), new GcbDataGenerator()),
            function() { return 'not a dispatcher'; }
        );

        $expectedMsg = 'A FastRoute\\Dispatcher must be returned from dispatcher callback injected in constructor';
        $this->setExpectedException(InvalidTypeException::class, $expectedMsg);

        $router->match(new Request());
    }

    public function testRouterNotFoundReturnsCorrectResolvedRoute() {
        $router = $this->getRouter();
        $resolved = $router->match(new Request());
        $this->assertInstanceOf(ResolvedRoute::class, $resolved);
        $this->assertTrue($resolved->isNotFound());
        $handler = $resolved->getController();
        /** @var Response $response */
        $response = $handler(new Request());
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertSame('Not Found', $response->getContent());
    }

    public function testRouterMethodNotAllowedReturnsCorrectResolvedRoute() {
        $router = $this->getRouter();
        $request = Request::create('http://labrador.dev/foo', 'POST');
        $router->get('/foo', 'foo#bar');
        $router->put('/foo', 'foo#baz');

        $resolved = $router->match($request);
        $this->assertInstanceOf(ResolvedRoute::class, $resolved);
        $this->assertTrue($resolved->isMethodNotAllowed());
        $this->assertSame(['GET', 'PUT'], $resolved->getAvailableMethods());
        $handler = $resolved->getController();
        /** @var Response $response */
        $response = $handler($request);
        $this->assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $response->getStatusCode());
        $this->assertSame('Method Not Allowed', $response->getContent());
    }

    public function testRouterIsOkReturnsCorrectResolvedRoute() {
        $router = $this->getRouter();
        $request = Request::create('http://labrador.dev/foo', 'GET');
        $router->get('/foo', 'handler');
        $this->mockResolver->expects($this->once())->method('resolve')->with($request, 'handler')->willReturn(function() { return 'OK'; });

        $resolved = $router->match($request);
        $this->assertInstanceOf(ResolvedRoute::class, $resolved);
        $this->assertTrue($resolved->isOk());
        $handler = $resolved->getController();
        $this->assertSame('OK', $handler());
    }

    public function testRouteWithParametersSetOnRequestAttributes() {
        $router = $this->getRouter();

        $router->post('/foo/{name}/{id}', 'attr#action');

        /** @var \Symfony\Component\HttpFoundation\Request $request */
        $request = Request::create('http://www.sprog.dev/foo/bar/qux', 'POST');
        $this->mockResolver->expects($this->once())->method('resolve')->with($request, 'attr#action')->willReturn(function() { return 'OK'; });

        $router->match($request);

        $this->assertSame('bar', $request->attributes->get('name'));
        $this->assertSame('qux', $request->attributes->get('id'));
    }

    public function testLabradorMetaRequestDataSetOnRequestAttributes() {
        $router = $this->getRouter();

        $router->post('/foo', 'controller#action');

        $request = Request::create('http://labrador.dev/foo', 'POST');
        $this->mockResolver->expects($this->once())->method('resolve')->with($request, 'controller#action')->will($this->returnValue(function() { return 'OK'; }));


        $router->match($request);

        $this->assertSame(['handler' => 'controller#action'], $request->attributes->get('_labrador'));
    }

    public function testGetRoutesWithJustOne() {
        $router = $this->getRouter();
        $router->get('/foo', 'handler');

        $routes = $router->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertInstanceOf(Route::class, $routes[0]);
        $this->assertSame('/foo', $routes[0]->getPattern());
        $this->assertSame('GET', $routes[0]->getMethod());
        $this->assertSame('handler', $routes[0]->getHandler());
    }

    public function testGetRoutesWithOnePatternSupportingMultipleMethods() {
        $router = $this->getRouter();
        $router->get('/foo/bar', 'foo_bar_get');
        $router->post('/foo/bar', 'foo_bar_post');
        $router->put('/foo/bar', 'foo_bar_put');

        $expected = [
            ['GET', '/foo/bar', 'foo_bar_get'],
            ['POST', '/foo/bar', 'foo_bar_post'],
            ['PUT', '/foo/bar', 'foo_bar_put']
        ];
        $actual = [];
        $routes = $router->getRoutes();
        foreach ($routes as $route) {
            $this->assertInstanceOf(Route::class, $route);
            $actual[] = [$route->getMethod(), $route->getPattern(), $route->getHandler()];
        }

        $this->assertSame($expected, $actual);
    }

    public function testGetRoutesWithStaticAndVariable() {
        $router = $this->getRouter();
        $router->get('/foo/bar/{id}', 'foo_bar_id');
        $router->get('/foo/baz/{name}', 'foo_baz_name');
        $router->post('/foo/baz', 'foo_baz_post');
        $router->put('/foo/quz', 'foo_quz_put');

        $expected = [
            ['GET', '/foo/bar/{id}', 'foo_bar_id'],
            ['GET', '/foo/baz/{name}', 'foo_baz_name'],
            ['POST', '/foo/baz', 'foo_baz_post'],
            ['PUT', '/foo/quz', 'foo_quz_put']
        ];
        $actual = [];
        $routes = $router->getRoutes();
        foreach ($routes as $route) {
            $this->assertInstanceOf(Route::class, $route);
            $actual[] = [$route->getMethod(), $route->getPattern(), $route->getHandler()];
        }

        $this->assertSame($expected, $actual);
    }

    public function testMountingRouterAddsPrefix() {
        $router = $this->getRouter();
        $router->mount('/prefix', function(FastRouteRouter $router) {
            $router->get('/foo', 'something');
        });
        $router->get('/noprefix', 'something else');

        $expected = [
            ['GET', '/prefix/foo', 'something'],
            ['GET', '/noprefix', 'something else']
        ];
        $actual = [];
        $routes = $router->getRoutes();
        foreach ($routes as $route) {
            $actual[] = [$route->getMethod(), $route->getPattern(), $route->getHandler()];
        }

        $this->assertSame($expected, $actual);
    }

    public function testNestedMountingAddsCorrectPrefixes() {
        $router = $this->getRouter();
        $router->mount('/foo', function(FastRouteRouter $router) {
            $router->delete('/foo-get', 'one');
            $router->mount('/bar', function(FastRouteRouter $router) {
                $router->post('/bar-post', 'two');
                $router->mount('/baz', function(FastRouteRouter $router) {
                    $router->put('/baz-put', 'three');
                });
            });
        });

        $expected = [
            ['DELETE', '/foo/foo-get', 'one'],
            ['POST', '/foo/bar/bar-post', 'two'],
            ['PUT', '/foo/bar/baz/baz-put', 'three']
        ];
        $actual = [];
        foreach ($router->getRoutes() as $route) {
            $actual[] = [$route->getMethod(), $route->getPattern(), $route->getHandler()];
        }

        $this->assertSame($expected, $actual);
    }

    public function testResolverReturnsFalseThrowsException() {
        $router = $this->getRouter();
        $this->mockResolver->expects($this->once())->method('resolve')->willReturn(false);
        $router->get('/foo', 'something');

        $this->setExpectedException(InvalidHandlerException::class, 'Could not resolve matched handler to a callable controller');
        $router->match(Request::create('http://labrador.dev/foo', 'GET'));
    }

    public function testSettingNotFoundController() {
        $router = $this->getRouter();
        $router->setNotFoundController(function() { return 'the set controller'; });
        $controller = $router->getNotFoundController();
        $this->assertSame('the set controller', $controller());
    }

    public function testSettingMethodNotAllowedController() {
        $router = $this->getRouter();
        $router->setMethodNotAllowedController(function() { return 'the set controller'; });
        $controller = $router->getMethodNotAllowedController();
        $this->assertSame('the set controller', $controller());
    }

    public function testSettingMountedRoot() {
        $router = $this->getRouter();
        $router->mount('/foo', function($router) {
            $router->get($router->root(), 'something');
        });
        $request = Request::create('http://example.com/foo');
        $this->mockResolver->expects($this->once())
                           ->method('resolve')
                           ->with($request, 'something')
                           ->willReturn(function() { return 'the set controller'; });

        $resolved = $router->match($request);
        $controller = $resolved->getController();
        $this->assertSame('the set controller', $controller());
    }

    public function testUsingRouterRootWithoutMount() {
        $request = Request::create('http://example.com');
        $router = $this->getRouter();
        $router->get($router->root(), 'something');
        $this->mockResolver->expects($this->once())
             ->method('resolve')
             ->with($request, 'something')
             ->willReturn(function() { return 'the set controller'; });

        $resolved = $router->match($request);
        $controller = $resolved->getController();
        $this->assertSame('the set controller', $controller());
    }

    public function testUrlDecodingCustomAttributes() {
        $request = Request::create('http://www.example.com/foo%20bar');
        $router = $this->getRouter();
        $router->get('/{param}', 'something');
        $this->mockResolver->expects($this->once())
                           ->method('resolve')
                           ->with($request, 'something')
                           ->willReturn(function() { return ''; });

        $resolved = $router->match($request);

        $this->assertTrue($resolved->isOk());
        $this->assertSame('foo bar', $request->attributes->get('param'));
    }

}
