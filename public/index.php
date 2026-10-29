<?php

// CVE-2024-50340 (Symfony Runtime 6.2, non corrigé dans cette branche) : si
// l'hébergeur active register_argc_argv, la chaîne de requête remplit argv et
// « ?--env=dev » basculerait l'application en mode développement. Une requête
// web n'a pas d'arguments de ligne de commande.
if (\PHP_SAPI !== 'cli') {
    unset($_SERVER['argv'], $_SERVER['argc']);
}

use App\Kernel;

require_once __DIR__ . '/../vendor/autoload_runtime.php';

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
