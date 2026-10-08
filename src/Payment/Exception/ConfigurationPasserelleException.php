<?php

namespace App\Payment\Exception;

/**
 * La passerelle réelle est activée mais mal configurée. Levée avant tout
 * appel réseau : aucune requête ne part avec des identifiants incomplets ou
 * vers une adresse non chiffrée.
 */
final class ConfigurationPasserelleException extends \RuntimeException
{
    /**
     * @param string[] $variables
     */
    public static function parametresManquants(string $passerelle, array $variables): self
    {
        return new self(sprintf(
            'Passerelle de paiement « %s » activée mais non configurée : renseignez %s dans .env.local.',
            $passerelle,
            implode(', ', $variables)
        ));
    }

    public static function connexionNonChiffree(): self
    {
        return new self('PAIEMENT_API_URL doit commencer par https:// : les échanges de paiement sont toujours chiffrés.');
    }

    /**
     * @param string[] $disponibles
     */
    public static function passerelleInconnue(string $nom, array $disponibles = []): self
    {
        return new self(sprintf(
            'PAIEMENT_PASSERELLE="%s" inconnu. Passerelles installées : %s.',
            $nom,
            $disponibles !== [] ? implode(', ', $disponibles) : 'aucune'
        ));
    }
}
