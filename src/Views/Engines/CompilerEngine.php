<?php

namespace Facade\Ignition\Views\Engines;

use Exception;
use Facade\Ignition\Exceptions\ViewExceptionWithSolution;
use Facade\IgnitionContracts\ProvidesSolution;
use ReflectionProperty;
use Illuminate\Support\Collection;
use Illuminate\Filesystem\Filesystem;
use Facade\Ignition\Exceptions\ViewException;
use Facade\Ignition\Views\Concerns\CollectsViewExceptions;
use Facade\Ignition\Views\Compilers\BladeSourceMapCompiler;

class CompilerEngine extends \Illuminate\View\Engines\CompilerEngine
{
    use CollectsViewExceptions;

    protected $currentPath = null;

    /**
     * Get the evaluated contents of the view.
     *
     * @param  string $path
     * @param  array $data
     *
     * @return string
     */
    public function get($path, array $data = [])
    {
        $this->currentPath = $path;

        $this->collectViewData($path, $data);

        return parent::get($path, $data);
    }

    /**
     * Handle a view exception.
     *
     * @param  \Exception $baseException
     * @param  int $obLevel
     *
     * @return void
     *
     * @throws \Exception
     */
    protected function handleViewException(Exception $baseException, $obLevel)
    {
        while (ob_get_level() > $obLevel) {
            ob_end_clean();
        }

        if ($baseException instanceof ViewException) {
            throw $baseException;
        }

        $viewExceptionClass = ViewException::class;

        if (in_array(ProvidesSolution::class, class_implements($baseException))) {
            $viewExceptionClass = ViewExceptionWithSolution::class;
        }

        $exception = new $viewExceptionClass(
            $this->getMessage($baseException),
            0,
            1,
            $this->getCompiledViewName($baseException->getFile()),
            $this->getBladeLineNumber($baseException->getFile(), $baseException->getLine()),
            $baseException
        );

        if ($viewExceptionClass === ViewExceptionWithSolution::class) {
            $exception->setSolution($baseException->getSolution());
        }

        $this->modifyViewsInTrace($exception);

        $exception->setView($this->getCompiledViewName($baseException->getFile()));
        $exception->setViewData($this->getCompiledViewData($baseException->getFile()));

        throw $exception;
    }

    protected function getBladeLineNumber(string $compiledPath, int $exceptionLineNumber):int
    {
        $viewPath = $this->getCompiledViewName($compiledPath);

        if (! $viewPath) {
            return $exceptionLineNumber;
        }

        $sourceMapCompiler = new BladeSourceMapCompiler(app(Filesystem::class), 'not-needed');

        return $sourceMapCompiler->detectLineNumber($viewPath, $exceptionLineNumber);
    }

    protected function modifyViewsInTrace(ViewException $exception)
    {
        $trace = Collection::make($exception->getTrace())
            ->map(function ($trace) {
                if ($compiledData = $this->findCompiledView($trace['file'])) {
                    $trace['file'] = $compiledData['path'];
                    $trace['line'] = $this->getBladeLineNumber($trace['file'], $trace['line']);
                }

                return $trace;
            })->toArray();

        $traceProperty = new ReflectionProperty('Exception', 'trace');
        $traceProperty->setAccessible(true);
        $traceProperty->setValue($exception, $trace);
    }
}
