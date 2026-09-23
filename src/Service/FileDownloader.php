<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

class FileDownloader
{
    private $slugger;
    private $filesystem;

    public function __construct(SluggerInterface $slugger, Filesystem $filesystem)
    {
        $this->slugger = $slugger;
        $this->filesystem = $filesystem;
    }

    public function download(array $folders = [], string $targetDirectory)
    {
        $zip = new ZipArchive();
        $zipfile = $targetDirectory."/candidat.zip";
        /*if ($zip->open($zipfile, ZipArchive::CREATE) === true) {
          echo $zip->addGlob($targetDirectory . "*.*", GLOB_BRACE, ["add_path" => "inside/","remove_all_path" => false]) ? "Folder added to zip" : "Error adding folder to zip" ;
          echo $zip->close() ? "Zip archive closed" : "Error closing zip archive" ;
        }
        else { echo "Failed to open/create $zipfile"; }
        */
        
        if ($zip->open($zipfile, ZipArchive::OVERWRITE | ZipArchive::CREATE) === true) {
            foreach ($folders as $folder) $this->zipall($folder, $targetDirectory, $zip); 
            echo $zip->close() ? "Zip archive closed" : "Error closing zip archive" ;
        }
        
        $response = new Response(file_get_contents($zipfile), Response::HTTP_OK, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="' . basename($zipfile) . '"', 'Content-Length' => filesize($zip_name)]);
        unlink($zipfile); 

        return $response;
    }

    public function zipall(string $folder, string $base, ZipArchive $ziparchive)
    {
        $options = ["remove_all_path" => false];
        $path = $base.$folder.'/';
        if ($folder != $base) $options["add_path"] = $path;
        echo $ziparchive->addGlob($folder."*.*", GLOB_BRACE, $options) ? "Folder added to zip" : "Error adding folder to zip" ;
        $folders = glob($folder . "*", GLOB_ONLYDIR);
        if (count($folders)!=0) foreach ($folders as $f) zipall($f."/", $base, $ziparchive);
    }
}
