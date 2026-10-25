<?php

declare(strict_types=1);

namespace DeployTool;

final class View
{
    /** Renders a page inside the layout. */
    public static function render(string $view, array $data, string $title): void
    {
        echo self::partial('layout', [
            'title' => $title,
            'page' => $data['page'] ?? '',
            'flash' => Http::takeFlash(),
            'content' => self::partial($view, $data),
        ]);
    }

    public static function partial(string $view, array $data = []): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require APP_ROOT . '/views/' . $view . '.php';
        return (string) ob_get_clean();
    }
}
