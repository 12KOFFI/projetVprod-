<?php

namespace App\Service;

use Twig\Environment;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\Response;

class PdfGenerator
{
    private $twig;
    private $filesystem;

    public function __construct(Environment $twig, Filesystem $filesystem)
    {
        $this->twig = $twig;
        $this->filesystem = $filesystem;
    }

    public function generate($path, $fileName, $template, $object)
    {
        $pdfOptions = new Options();
        $pdfOptions->set(['enable_remote' => true, 'chroot'  => '/public/']);
        $dompdf = new Dompdf($pdfOptions);
        $dompdf->loadHtml($this->twig->render($template, $object));
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        try {
            $this->filesystem->mkdir(Path::normalize($path));
            file_put_contents($path . '/ ' . $fileName, $dompdf->output());
        } catch (IOExceptionInterface $exception) {
            dump("An error occurred while creating your directory at " . $exception->getPath());
        }
    }

    public function stream($template, $object): Response
    {
        $pdfOptions = new Options();
        $pdfOptions->setIsRemoteEnabled(true);
        $dompdf = new Dompdf($pdfOptions);
        $dompdf->loadHtml($this->twig->render($template, $object));
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new Response(
            $dompdf->output(),
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . uniqid() . '.pdf"',
            ]
        );
    }


    public function imageToBase64($path)
    {
        $path = $path;
        $type = pathinfo($path, PATHINFO_EXTENSION);
        $data = file_get_contents($path);
        $base64 = 'data:image/' . $type . ';base64,' . base64_encode($data);
        return $base64;
    }
}
