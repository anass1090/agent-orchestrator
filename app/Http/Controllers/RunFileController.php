<?php

namespace App\Http\Controllers;

use App\Models\Run;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

class RunFileController extends Controller
{
    /**
     * Serve a stored run file as plain text, never outside the run's directory.
     */
    public function __invoke(Run $run, string $path): Response
    {
        $root = realpath($run->storagePath());
        $file = realpath($run->storagePath($path));

        abort_if($root === false || $file === false || ! str_starts_with($file, $root.'/') || ! is_file($file), 404);

        return response(File::get($file), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
