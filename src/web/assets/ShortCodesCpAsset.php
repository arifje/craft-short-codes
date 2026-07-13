<?php

declare(strict_types=1);

namespace arjanbrinkman\craftshortcodes\web\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

final class ShortCodesCpAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [
        CpAsset::class,
    ];

    public $css = [
        'short-codes.css',
    ];

    public $js = [
        'short-codes.js',
    ];
}
