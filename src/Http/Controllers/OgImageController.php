<?php

namespace Backstage\OgImage\Laravel\Http\Controllers;

use Backstage\OgImage\Laravel\Facades\OgImage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OgImageController
{
    public function __invoke(Request $request): Response
    {
        // The local-only preview route (og-image.html) renders HTML and never
        // saves a file, so it may be viewed without a signature. Every other
        // request generates/serves a cached image keyed by the signature, so a
        // valid signature is required — otherwise saveImage() receives a null
        // filename and throws a TypeError.
        if ($request->route()->getName() !== 'og-image.html' && ! $request->hasValidSignature()) {
            abort(403);
        }

        return OgImage::getResponse($request);
    }
}
