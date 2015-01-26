<?php

namespace Swapbot\Http\Controllers\API\Base;

use Exception;
use Swapbot\Http\Controllers\Controller;

class APIController extends Controller {

    public function __construct() {
        // catch all errors and return a JSON response
        $this->middleware('apierrors');

        // require hmacauth middleware for all API requests by default
        $this->middleware('hmacauth');
    }

}
