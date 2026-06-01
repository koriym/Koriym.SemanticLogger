<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * Renders a semantic log into a string representation.
 *
 * Concrete renderers (tree, markdown, ...) implement this interface. The log
 * itself stays unaware of any output format: callers invoke
 * {@see LogJson::render()} and pass the renderer, which is then handed the log
 * back (double dispatch) and decides how to present it.
 */
interface LogRendererInterface
{
    public function render(LogJson $log): string;
}
