<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Preview-раздел: каталог demo и показ отдельной demo-страницы.
 *
 * Доступен только при включённом флаге config('preview.enabled'), который
 * поднимается лишь в локальном окружении. Вне local любой preview-маршрут
 * отвечает 404.
 */
class PreviewController extends Controller
{
    public function index(): View
    {
        $this->ensureEnabled();

        $demos = [];

        foreach (array_reverse((array) config('preview.demos', [])) as $slug => $demo) {
            $demos[] = ['slug' => $slug] + (array) $demo;
        }

        return view('preview.index', ['demos' => $demos]);
    }

    public function show(string $demo): View
    {
        $this->ensureEnabled();

        $entry = config('preview.demos.'.$demo);

        abort_unless(is_array($entry), 404);

        $viewName = 'preview.demos.'.$demo.'.index';

        abort_unless(view()->exists($viewName), 404);

        // folderPath в config/preview.php — путь от корня приложения.
        $folder = trim((string) ($entry['folderPath'] ?? ''), '/');

        abort_if($folder === '', 500, 'Preview demo "'.$demo.'": в config/preview.php не задан folderPath.');

        $mockPath = base_path($folder.'/mock.php');

        abort_unless(is_file($mockPath), 500, 'Preview demo "'.$demo.'": mock.php не найден — '.$mockPath);

        /** @var view-string&non-falsy-string $viewName */
        return view($viewName, [
            'slug' => $demo,
            'demo' => $entry,
            'mock' => require $mockPath,
        ]);
    }

    private function ensureEnabled(): void
    {
        abort_unless((bool) config('preview.enabled', false), 404);
    }
}
