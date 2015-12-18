<?php

declare(strict_types=1);

/**
 * Creates a callable from the name of an object and method to invoke;
 * the controller class and action should be delimited by a '#'.
 * 
 * @license See LICENSE in source root
 */

namespace Cspray\Labrador\Http\HandlerResolver;

use Cspray\Labrador\Http\Exception\InvalidHandlerException;
use Auryn\Injector;
use Auryn\InjectorException;

class ControllerActionResolver implements HandlerResolver {

    /**
     * @property Injector
     */
    private $injector;
    private $errorMsg = [
        'invalid_handler_format' => 'The handler, %s, is invalid; all handlers must have 1 hashtag delimiting the controller and action.',
        'controller_create_error' => 'An error was encountered creating the controller for %s.',
        'controller_not_callable' => 'The controller and action, %s::%s, is not callable. Please ensure that a publicly accessible method is available with this name.'
    ];

    /**
     * @param Injector $injector
     */
    function __construct(Injector $injector) {
        $this->injector = $injector;
    }

    /**
     * Any handler that has '#' in the name will make the Resolver attempt to
     * instantiate a class based on the string to the left of the '#'.
     *
     * @param string $handler
     * @return callable|false
     * @throws InvalidHandlerException
     */
    function resolve($handler) {
        if (!$this->verifyFormat($handler)) {
            return false;
        }
        // @TODO allow the explode delimiter to be configurable
        list($controllerName, $action) = explode('#', $handler);
        try {
            $controller = $this->injector->make($controllerName);
        } catch (InjectorException $exc) {
            $msg = $this->errorMsg['controller_create_error'];
            throw new InvalidHandlerException(sprintf($msg, $handler), 500, $exc);
        }

        $cb = [$controller, $action];
        if (!is_callable($cb)) {
            $msg = $this->errorMsg['controller_not_callable'];
            throw new InvalidHandlerException(sprintf($msg, $controllerName, $action), 500);
        }

        return $cb;
    }

    private function verifyFormat(string $handler) : bool {
        // intentionally not checking for strict boolean false
        // we don't want to accept handlers that begin with #
        if (!strpos($handler, '#')) {
            return false;
        }

        return true;
    }

} 