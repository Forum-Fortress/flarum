<?php
// Copyright (c) 2026 Marscastle Ltd trading as Forum Fortress
// SPDX-License-Identifier: GPL-2.0-or-later

namespace ForumFortress\Flarum\Frontend;

use Flarum\Frontend\Compiler\LessCompiler;

final class MemorySafeLessCompiler extends LessCompiler
{
    protected function compile(array $sources): string
    {
        // Earlier JS/LESS builds can leave cyclic compiler objects behind.
        // Free them before allocating another stylesheet's parse tree.
        gc_collect_cycles();
        gc_mem_caches();

        try {
            return parent::compile($sources);
        } finally {
            // The parser is now out of scope. Reclaim its cycles before Flarum
            // writes assets and detects MIME types for large JavaScript chunks.
            gc_collect_cycles();
            gc_mem_caches();
        }
    }
}
