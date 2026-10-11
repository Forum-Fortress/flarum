<?php
// Copyright (c) 2026 Marscastle Ltd trading as Forum Fortress
// SPDX-License-Identifier: GPL-2.0-or-later

namespace ForumFortress\Flarum;

use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Flarum\Foundation\Application;
use Flarum\Frontend\Compiler\LessCompiler;
use ForumFortress\Flarum\Frontend\MemorySafeLessCompiler;
use Illuminate\Contracts\Container\Container;

final class AssetMemory implements ExtenderInterface
{
    public function extend(Container $container, ?Extension $extension = null): void
    {
        // Flarum 2 rebuilds every frontend in one cache-clear request. Keep its
        // compiler behavior, but release completed parse trees between builds.
        // Respect a compiler explicitly registered by another extension.
        if (str_starts_with(Application::VERSION, '2.') && ! $container->bound(LessCompiler::class)) {
            $container->bind(LessCompiler::class, MemorySafeLessCompiler::class);
        }
    }
}
