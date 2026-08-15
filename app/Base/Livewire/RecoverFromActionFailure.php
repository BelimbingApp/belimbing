<?php

namespace App\Base\Livewire;

use App\Base\Foundation\Exceptions\BlbException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\ComponentHook;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Turns an unexpected failure inside a Livewire action into an error
 * notification on the page the user is already looking at.
 *
 * Laravel's default for an uncaught throwable is a 500, so every Livewire
 * action was one unhandled exception away from throwing the user out of their
 * work. The alternative was a try/catch in each action — 31 of 189 components
 * had written one, which is what "handled by convention" looks like when the
 * convention is memory. This is that convention in one place: Livewire fires an
 * `exception` hook around every action call, and stopping propagation there
 * leaves the component mounted and re-rendered instead of replacing the screen
 * with an error page.
 *
 * Only genuinely unexpected failures are recovered. Validation, auth, and HTTP
 * exceptions are how the framework expresses an outcome — swallowing those
 * would break validation messages and turn a 403 into a shrug.
 *
 * render() is deliberately not covered: Livewire assigns the return of
 * wrap($component)->render() straight into the view pipeline, so recovering
 * there yields a null view and a worse failure than the one being handled.
 */
final class RecoverFromActionFailure extends ComponentHook
{
    /**
     * Exceptions that carry a deliberate outcome and must reach the handler.
     *
     * BlbException is on this list because bootstrap/app.php already maps each
     * reason code to a reviewed status (403, 409, 422); recovering it here
     * would quietly override that policy.
     *
     * @var list<class-string>
     */
    private const PROPAGATE = [
        ValidationException::class,
        AuthenticationException::class,
        AuthorizationException::class,
        ModelNotFoundException::class,
        HttpExceptionInterface::class,
        BlbException::class,
    ];

    /**
     * Livewire fires `exception` for render() as well as for action calls, and
     * the hook is not told which it was. This flag is set for the duration of
     * an action call so only that path is recovered.
     */
    private bool $insideActionCall = false;

    public function call($method, $params, $returnEarly, $metadata, $componentContext)
    {
        $this->insideActionCall = true;

        return function (): void {
            $this->insideActionCall = false;
        };
    }

    public function exception(Throwable $e, callable $stopPropagation): void
    {
        if (! $this->insideActionCall || $this->carriesItsOwnOutcome($e)) {
            return;
        }

        // report() feeds both the log and the status-bar diagnostics buffer,
        // so recovering in front of the user does not hide the defect from
        // whoever has to fix it.
        report($e);

        $this->component->dispatch('notify', message: $this->message($e), variant: 'error');

        $stopPropagation();
    }

    private function carriesItsOwnOutcome(Throwable $e): bool
    {
        foreach (self::PROPAGATE as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * The copy deliberately does not promise that nothing was written: an
     * action can fail partway, and a reassurance we cannot verify is worse
     * than none. It says what is true — it did not finish, and someone knows.
     */
    private function message(Throwable $e): string
    {
        $message = __('That action did not finish. The error has been recorded — if it keeps happening, tell your administrator.');

        if (config('app.debug')) {
            $message .= ' ['.class_basename($e).': '.$e->getMessage().']';
        }

        return $message;
    }
}
