<?php
namespace App\Base\Pdf\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SignedRenderController
{
    public function show(Request $request): Response
    {
        $claims = $request->attributes->get('blb.pdf.claims');

        if (! is_array($claims) || ! isset($claims['view'])) {
            throw new NotFoundHttpException();
        }

        $view = (string) $claims['view'];
        $data = is_array($claims['data'] ?? null) ? $claims['data'] : [];

        return response()
            ->view($view, $data)
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
