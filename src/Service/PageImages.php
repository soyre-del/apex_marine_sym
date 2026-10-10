<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PageImages
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDirectory,
    ) {
    }

    public function resolve(string $filename): ?string
    {
        foreach ([
            'assets/images/'.$filename => 'images/'.$filename,
            'public/assets/images/'.$filename => 'assets/images/'.$filename,
        ] as $file => $assetPath) {
            $path = $this->projectDirectory.'/'.$file;

            if (is_file($path) && @getimagesize($path) !== false) {
                return $assetPath;
            }
        }

        return null;
    }
}
