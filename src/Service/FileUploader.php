<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

class FileUploader
{
    private $slugger;
    private $filesystem;

    public function __construct(SluggerInterface $slugger, Filesystem $filesystem)
    {
        $this->slugger = $slugger;
        $this->filesystem = $filesystem;
    }

    /**
     * Upload standard d'un fichier
     * Retourne le nom du fichier ou null
     */
    public function upload(?UploadedFile $file, string $targetDirectory, ?string $previous = null): ?string
    {
        if (!$file) {
            return $previous;
        }

        try {
            // Créer le dossier s'il n'existe pas
            if (!is_dir($targetDirectory)) {
                mkdir($targetDirectory, 0777, true);
            }

            $fileName = uniqid() . '.' . $file->guessExtension();
            $file->move($targetDirectory, $fileName);
            
            // Supprimer l'ancien fichier si existant
            if ($previous && $this->filesystem->exists($targetDirectory . '/' . $previous)) {
                $this->filesystem->remove($targetDirectory . '/' . $previous);
            }
            
            return $fileName;
        } catch (FileException $e) {
            throw new \Exception('Erreur lors de l\'upload du fichier : ' . $e->getMessage());
        }
    }
    
    /**
     * Upload avec un nom personnalisé
     */
    public function uploadWithName(?UploadedFile $file, string $targetDirectory, string $mask, ?string $previous = null): ?string
    {
        if (!$file) {
            return $previous;
        }

        try {
            // Créer le dossier s'il n'existe pas
            if (!is_dir($targetDirectory)) {
                mkdir($targetDirectory, 0777, true);
            }

            $extension = $file->guessExtension() ?? pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION);
            $fileName = $mask . '.' . $extension;
            
            $file->move($targetDirectory, $fileName);
            
            // Supprimer l'ancien fichier si existant
            if ($previous && $previous !== $fileName && $this->filesystem->exists($targetDirectory . '/' . $previous)) {
                $this->filesystem->remove($targetDirectory . '/' . $previous);
            }
            
            return $fileName;
        } catch (FileException $e) {
            throw new \Exception('Erreur lors de l\'upload du fichier : ' . $e->getMessage());
        }
    }

    /**
     * Upload avec conservation du nom original (slugifié)
     */
    public function uploadWithOriginalName(?UploadedFile $file, string $targetDirectory, ?string $previous = null): ?string
    {
        if (!$file) {
            return $previous;
        }

        try {
            // Créer le dossier s'il n'existe pas
            if (!is_dir($targetDirectory)) {
                mkdir($targetDirectory, 0777, true);
            }

            $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $safeFilename = $this->slugger->slug($originalFilename);
            $fileName = $safeFilename . '-' . uniqid() . '.' . $file->guessExtension();
            
            $file->move($targetDirectory, $fileName);
            
            // Supprimer l'ancien fichier si existant
            if ($previous && $this->filesystem->exists($targetDirectory . '/' . $previous)) {
                $this->filesystem->remove($targetDirectory . '/' . $previous);
            }
            
            return $fileName;
        } catch (FileException $e) {
            throw new \Exception('Erreur lors de l\'upload du fichier : ' . $e->getMessage());
        }
    }

    /**
     * Remplace un fichier existant
     */
    public function replace(string $fileName, ?UploadedFile $file, string $targetDirectory): ?string
    {
        if (!$file) {
            return $fileName;
        }

        try {
            // Créer le dossier s'il n'existe pas
            if (!is_dir($targetDirectory)) {
                mkdir($targetDirectory, 0777, true);
            }

            $file->move($targetDirectory, $fileName);
            return $fileName;
        } catch (FileException $e) {
            throw new \Exception('Erreur lors du remplacement du fichier : ' . $e->getMessage());
        }
    }

    /**
     * Supprime un dossier ou fichier
     */
    public function remove(string $target): void
    {
        try {
            if ($this->filesystem->exists($target)) {
                $this->filesystem->remove($target);
            }
        } catch (\Exception $e) {
            throw new \Exception('Erreur lors de la suppression : ' . $e->getMessage());
        }
    }

    /**
     * Vérifie si un fichier existe
     */
    public function exists(string $path): bool
    {
        return $this->filesystem->exists($path);
    }

    /**
     * Crée un dossier
     */
    public function mkdir(string $path, int $mode = 0777): void
    {
        if (!is_dir($path)) {
            $this->filesystem->mkdir($path, $mode);
        }
    }
}