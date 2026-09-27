<?php

declare(strict_types=1);

/**
 * 极简自动加载：Evolveproj\Foo → src/Foo.php。
 *
 * 本题只用 PHP 标准库，不引入 composer，因此不生成 vendor/autoload.php。
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Evolveproj\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    if ($relative === '' || strpos($relative, '..') !== false) {
        return;
    }

    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
