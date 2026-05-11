<?php
namespace App\Base\Pdf\Http\Middleware;

use App\Base\Pdf\Services\SignedRenderTokenStore;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class VerifyRenderToken
{
    public function __construct(
        private readonly SignedRenderTokenStore $tokens,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tokenId = (string) $request->route('token');
        $claims = $this->tokens->consume($tokenId);

        if ($claims === null) {
            throw new NotFoundHttpException();
        }

        $request->attributes->set('blb.pdf.claims', $claims);

        $userId = $claims['user_id'] ?? null;
        if ($userId !== null) {
            Auth::onceUsingId($userId);
        }

        return $next($request);
    }
}
