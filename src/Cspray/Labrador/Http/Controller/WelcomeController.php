<?php

declare(strict_types=1);

/**
 * @license See LICENSE in source root
 */

namespace Cspray\Labrador\Http\Controller;

use Symfony\Component\HttpFoundation\Response;

class WelcomeController extends Controller {

    public function index() : Response {
        return new Response($this->getHtml());
    }

    private function getHtml() : string {
        return file_get_contents(dirname(__DIR__) . '/_templates/welcome.html');
    }

}
