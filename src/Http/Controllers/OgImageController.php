<?php

namespace Backstage\OgImage\Laravel\Http\Controllers;

use Backstage\OgImage\Laravel\Facades\OgImage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OgImageController
{
    public function __invoke(Request $request): Response
    {
        // In local development the image always renders, regardless of signature,
        // so previews work while iterating. Outside local a valid signature is
        // required. getResponse() derives a cache filename from the request
        // parameters when no signature is present, so an unsigned local request
        // never reaches saveImage() with a null filename.
        if (! app()->environment('local') && ! $request->hasValidSignature()) {
            abort(403);
        }

        return OgImage::getResponse($request);
    }
}
